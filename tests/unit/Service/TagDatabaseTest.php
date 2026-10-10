<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Exception\PreconditionFailedException;
use OCA\QOwnNotes\Exception\TagDatabaseException;
use OCA\QOwnNotes\Model\NoteLocation;
use OCA\QOwnNotes\Service\TagDatabase;
use OCA\QOwnNotes\Service\TagWriter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PDO;
use PHPUnit\Framework\TestCase;

class TagDatabaseTest extends TestCase {
	private string $dir;
	/** Path of the simulated notes.sqlite in the note folder */
	private string $storedPath;
	private int $etagCounter = 1;
	private int $writes = 0;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/qownnotes-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->storedPath = $this->dir . '/notes.sqlite';
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	private function createDatabase(?ILockingProvider $lockingProvider = null): TagDatabase {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(fn (): string => tempnam($this->dir, 'tmp'));
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($this->createMock(ICache::class));

		return new TagDatabase($tempManager, $cacheFactory, $lockingProvider ?? $this->createMock(ILockingProvider::class));
	}

	private function createFile(?int $reportedSize = null): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturnCallback(fn (): string => 'etag-' . $this->etagCounter);
		$file->method('getSize')->willReturnCallback(fn (): int => $reportedSize ?? (int)filesize($this->storedPath));
		$file->method('isUpdateable')->willReturn(true);
		$file->method('fopen')->willReturnCallback(fn () => fopen($this->storedPath, 'rb'));
		$file->method('putContent')->willReturnCallback(function ($handle): void {
			file_put_contents($this->storedPath, stream_get_contents($handle));
			$this->etagCounter++;
			$this->writes++;
		});
		return $file;
	}

	private function createFolder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);
		$folder->method('isCreatable')->willReturn(true);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => $name === 'notes.sqlite' && is_file($this->storedPath));
		$folder->method('get')->willReturnCallback(fn (): File => $this->createFile());
		$folder->method('newFile')->willReturnCallback(function (string $name, $handle): File {
			file_put_contents($this->storedPath, stream_get_contents($handle));
			$this->writes++;
			return $this->createFile();
		});
		return $folder;
	}

	private function createStoredDatabase(int $version = 16): PDO {
		$pdo = new PDO('sqlite:' . $this->storedPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		foreach (TagDatabase::CREATE_SCHEMA as $statement) {
			$pdo->exec($statement);
		}
		$pdo->exec("INSERT INTO appData (name, value) VALUES ('database_version', '$version')");
		return $pdo;
	}

	public function testReadMissingDatabase(): void {
		$snapshot = $this->createDatabase()->read($this->createFolder());
		$this->assertFalse($snapshot->exists());
		$this->assertSame([], $snapshot->tags);
	}

	public function testRealDesktopSchemaCanBeReadAndModifiedWithoutMigration(): void {
		foreach (['desktop-16-empty.sqlite', 'desktop-16-seeded.sqlite'] as $fixture) {
			copy(__DIR__ . '/../../fixtures/notes-sqlite/' . $fixture, $this->storedPath);
			$pdo = new PDO('sqlite:' . $this->storedPath);
			$schema = $pdo->query('SELECT type, name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
			$trash = $pdo->query('SELECT * FROM trashItem')->fetchAll(PDO::FETCH_ASSOC);
			$pageSize = $pdo->query('PRAGMA page_size')->fetchColumn();
			$encoding = $pdo->query('PRAGMA encoding')->fetchColumn();
			unset($pdo);

			$database = $this->createDatabase();
			$before = $database->read($this->createFolder());
			$this->assertSame(16, $before->schemaVersion);
			$this->assertTrue($before->writable);
			$after = $database->modify($this->createFolder(), static function (TagWriter $writer): void {
				$ids = $writer->resolvePath(['work', 'Server child'], true);
				$writer->linkNote($ids[1], new NoteLocation('Server.md', 'Deep/Nested'));
				$writer->relinkSubFolder('Work/Notes', 'Archive/Notes');
			});
			$this->assertCount(count($before->links) + 1, $after->links);
			if ($fixture === 'desktop-16-seeded.sqlite') {
				$this->assertSame('#ff8800', $after->tags[1]['color']);
				$this->assertSame('#ffaa33', $after->tags[1]['darkColor']);
				$this->assertSame('Work', $after->tags[1]['name'], 'Case-insensitive lookup reuses the Desktop tag');
				$this->assertSame('Archive/Notes', $after->links[0]['subFolderPath']);
				$this->assertTrue($after->links[2]['stale']);
			}

			$pdo = new PDO('sqlite:' . $this->storedPath);
			$this->assertSame($schema, $pdo->query('SELECT type, name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC));
			$this->assertSame($trash, $pdo->query('SELECT * FROM trashItem')->fetchAll(PDO::FETCH_ASSOC));
			$this->assertSame($pageSize, $pdo->query('PRAGMA page_size')->fetchColumn());
			$this->assertSame($encoding, $pdo->query('PRAGMA encoding')->fetchColumn());
			$this->assertSame('16', $pdo->query("SELECT value FROM appData WHERE name='database_version'")->fetchColumn());
			$this->assertSame('ok', $pdo->query('PRAGMA quick_check')->fetchColumn());
			$this->assertSame("\x01\x01", substr((string)file_get_contents($this->storedPath), 18, 2));
			unset($pdo);
		}
	}

	public function testNegativeFixturesAreRejectedWithoutUpload(): void {
		foreach (['synthetic-wal.sqlite', 'synthetic-corrupt.sqlite'] as $fixture) {
			copy(__DIR__ . '/../../fixtures/notes-sqlite/' . $fixture, $this->storedPath);
			$before = file_get_contents($this->storedPath);
			try {
				$this->createDatabase()->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Server', 0));
				$this->fail('Negative fixture should be rejected');
			} catch (TagDatabaseException) {
				$this->assertSame($before, file_get_contents($this->storedPath));
				$this->assertSame(0, $this->writes);
				$this->assertSame([], glob($this->dir . '/tmp*'));
			}
		}
	}

	public function testModifyCreatesDatabaseWithDesktopSchema(): void {
		$snapshot = $this->createDatabase()->modify($this->createFolder(), static function (TagWriter $writer): void {
			$writer->linkNote($writer->createTag('Work', 0), new NoteLocation('Note.md', ''));
		});

		$this->assertSame(1, $this->writes);
		$this->assertSame(16, $snapshot->schemaVersion);
		$this->assertSame(['Work'], array_column($snapshot->tags, 'name'));
		$this->assertSame('Note.md', $snapshot->links[0]['fileName']);

		$header = (string)file_get_contents($this->storedPath, false, null, 0, 20);
		$this->assertSame([1, 1], [ord($header[18]), ord($header[19])], 'Rollback-journal format expected');
		$pdo = new PDO('sqlite:' . $this->storedPath);
		$this->assertSame('16', $pdo->query("SELECT value FROM appData WHERE name = 'database_version'")->fetchColumn());
		$this->assertSame(['appData', 'noteTagLink', 'tag', 'trashItem'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
	}

	public function testModifyKeepsOtherTables(): void {
		$pdo = $this->createStoredDatabase();
		$pdo->exec("INSERT INTO trashItem (file_name, file_size) VALUES ('Deleted.md', 5)");
		$pdo->exec('CREATE TABLE futureTable (value TEXT)');
		$pdo->exec("INSERT INTO futureTable VALUES ('kept')");
		unset($pdo);

		$this->createDatabase()->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Tag', 0));

		$pdo = new PDO('sqlite:' . $this->storedPath);
		$this->assertSame('Deleted.md', $pdo->query('SELECT file_name FROM trashItem')->fetchColumn());
		$this->assertSame('kept', $pdo->query('SELECT value FROM futureTable')->fetchColumn());
	}

	public function testNoChangesNoUpload(): void {
		$this->createStoredDatabase();
		$this->createDatabase()->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->relinkSubFolder('missing', 'other'));
		$this->assertSame(0, $this->writes);
	}

	public function testIfMatchMismatch(): void {
		$this->createStoredDatabase();
		$this->expectException(PreconditionFailedException::class);
		$this->createDatabase()->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Tag', 0), '"outdated"');
	}

	public function testIfMatch(): void {
		$this->createStoredDatabase();
		$snapshot = $this->createDatabase()->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Tag', 0), '"etag-1"');
		$this->assertSame('etag-2', $snapshot->etag);
	}

	public function testUnknownSchemaIsReadOnly(): void {
		$this->createStoredDatabase(17);
		$database = $this->createDatabase();
		$this->assertFalse($database->read($this->createFolder())->writable);

		$this->expectException(TagDatabaseException::class);
		$database->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Tag', 0));
	}

	public function testOutdatedSchemaIsRejected(): void {
		$this->createStoredDatabase(14);
		$this->expectException(TagDatabaseException::class);
		$this->createDatabase()->read($this->createFolder());
	}

	public function testWalDatabaseIsRejected(): void {
		$pdo = $this->createStoredDatabase();
		$pdo->exec('PRAGMA journal_mode = WAL');
		unset($pdo);

		$this->expectException(TagDatabaseException::class);
		$this->expectExceptionMessage('write-ahead logging');
		$this->createDatabase()->read($this->createFolder());
	}

	public function testDamagedDatabaseIsRejected(): void {
		file_put_contents($this->storedPath, 'not a database');
		$this->expectException(TagDatabaseException::class);
		$this->createDatabase()->read($this->createFolder());
	}

	public function testRejectedCopiesAreRemoved(): void {
		foreach ([false, true] as $wal) {
			if ($wal) {
				$pdo = $this->createStoredDatabase();
				$pdo->exec('PRAGMA journal_mode = WAL');
				unset($pdo);
			} else {
				file_put_contents($this->storedPath, 'not a database');
			}

			foreach ([false, true] as $write) {
				try {
					$database = $this->createDatabase();
					if ($write) {
						$database->modify($this->createFolder(), static fn (TagWriter $writer) => $writer->createTag('Tag', 0));
					} else {
						$database->read($this->createFolder());
					}
					$this->fail('Invalid database should be rejected');
				} catch (TagDatabaseException) {
					$this->assertSame([], glob($this->dir . '/tmp*'));
					$this->assertSame(0, $this->writes);
				}
			}
			unlink($this->storedPath);
		}
	}

	public function testActualCopySizeIsLimited(): void {
		$handle = fopen($this->storedPath, 'wb');
		ftruncate($handle, TagDatabase::MAX_SIZE + 2);
		fclose($handle);
		$file = $this->createFile(100);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		try {
			$this->createDatabase()->read($folder);
			$this->fail('Oversized database should be rejected even with a small reported size');
		} catch (TagDatabaseException $e) {
			$this->assertSame('notes.sqlite is too large', $e->getMessage());
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testSourceOpenFailureRemovesCopy(): void {
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('fopen')->willReturn(false);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		try {
			$this->createDatabase()->read($folder);
			$this->fail('Unreadable database should be rejected');
		} catch (TagDatabaseException $e) {
			$this->assertSame('notes.sqlite can not be read', $e->getMessage());
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testSourceOpenExceptionRemovesCopy(): void {
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('fopen')->willThrowException(new \RuntimeException('Storage unavailable'));
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		try {
			$this->createDatabase()->read($folder);
			$this->fail('Storage exception should propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('Storage unavailable', $e->getMessage());
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testFailedTransactionDoesNotUploadAndRemovesCopy(): void {
		$this->createStoredDatabase();
		$before = file_get_contents($this->storedPath);
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())->method('acquireLock')->with('qownnotes/notes.sqlite/1', ILockingProvider::LOCK_EXCLUSIVE, 'notes.sqlite');
		$lockingProvider->expects($this->once())->method('releaseLock')->with('qownnotes/notes.sqlite/1', ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->createDatabase($lockingProvider)->modify($this->createFolder(), static function (TagWriter $writer): void {
				$writer->createTag('Not committed', 0);
				throw new \RuntimeException('Operation failed');
			});
			$this->fail('Failed operation should propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('Operation failed', $e->getMessage());
			$this->assertSame($before, file_get_contents($this->storedPath));
			$this->assertSame(0, $this->writes);
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testConcurrentChangeIsRetried(): void {
		$this->createStoredDatabase();
		$calls = 0;
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())->method('acquireLock')->with('qownnotes/notes.sqlite/1', ILockingProvider::LOCK_EXCLUSIVE, 'notes.sqlite');
		$lockingProvider->expects($this->once())->method('releaseLock')->with('qownnotes/notes.sqlite/1', ILockingProvider::LOCK_EXCLUSIVE);
		$snapshot = $this->createDatabase($lockingProvider)->modify($this->createFolder(), function (TagWriter $writer) use (&$calls): void {
			$calls++;
			$writer->createTag('Tag ' . $calls, 0);
			if ($calls === 1) {
				$this->simulateClientWrite('Client tag');
			}
		});

		$this->assertSame(2, $calls);
		$this->assertSame(1, $this->writes);
		$this->assertSame(['Client tag', 'Tag 2'], array_column($snapshot->tags, 'name'));
		$this->assertSame([], glob($this->dir . '/tmp*'));
	}

	private function simulateClientWrite(string $name): void {
		// Simulate a whole-file upload changing the stored database, not the server's private copy.
		$pdo = new PDO('sqlite:' . $this->storedPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->prepare('INSERT INTO tag (name) VALUES (?)')->execute([$name]);
		$this->etagCounter++;
	}

	public function testConcurrentChangeWithIfMatchDoesNotOverwriteClient(): void {
		$this->createStoredDatabase();
		$calls = 0;
		try {
			$this->createDatabase()->modify($this->createFolder(), function (TagWriter $writer) use (&$calls): void {
				$calls++;
				$writer->createTag('Server tag', 0);
				$this->simulateClientWrite('Client tag');
			}, '"etag-1"');
			$this->fail('Explicit If-Match must reject the changed database');
		} catch (PreconditionFailedException $e) {
			$this->assertSame(['etag' => 'etag-2'], $e->getCurrentData());
			$this->assertSame(1, $calls);
			$this->assertSame(0, $this->writes);
			$this->assertSame(['Client tag'], array_column($this->createDatabase()->read($this->createFolder())->tags, 'name'));
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testRepeatedConcurrentChangesStopAfterOneRetry(): void {
		$this->createStoredDatabase();
		$calls = 0;
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())->method('acquireLock');
		$lockingProvider->expects($this->once())->method('releaseLock');
		try {
			$this->createDatabase($lockingProvider)->modify($this->createFolder(), function (TagWriter $writer) use (&$calls): void {
				$calls++;
				$writer->createTag('Server tag', 0);
				$this->simulateClientWrite('Client tag ' . $calls);
			});
			$this->fail('Repeated changes must exhaust the retry');
		} catch (PreconditionFailedException) {
			$this->assertSame(2, $calls);
			$this->assertSame(0, $this->writes);
			$this->assertSame(['Client tag 1', 'Client tag 2'], array_column($this->createDatabase()->read($this->createFolder())->tags, 'name'));
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}

	public function testLockFailureDoesNotStartOperation(): void {
		$this->createStoredDatabase();
		$before = file_get_contents($this->storedPath);
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())->method('acquireLock')->willThrowException(new LockedException('notes.sqlite'));
		$lockingProvider->expects($this->never())->method('releaseLock');
		$calls = 0;
		try {
			$this->createDatabase($lockingProvider)->modify($this->createFolder(), static function (TagWriter $writer) use (&$calls): void {
				$calls++;
				$writer->createTag('Server tag', 0);
			});
			$this->fail('Lock failure should propagate');
		} catch (LockedException) {
			$this->assertSame(0, $calls);
			$this->assertSame(0, $this->writes);
			$this->assertSame($before, file_get_contents($this->storedPath));
			$this->assertSame([], glob($this->dir . '/tmp*'));
		}
	}
}
