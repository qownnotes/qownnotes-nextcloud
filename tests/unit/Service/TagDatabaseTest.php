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
use PDO;
use PHPUnit\Framework\TestCase;

class TagDatabaseTest extends TestCase {
	private string $dir;
	/** Path of the simulated notes.sqlite in the note folder */
	private string $storedPath;
	private int $etagCounter = 1;
	private int $writes = 0;
	/** @var (callable(): void)|null simulates another client writing between read and write */
	private $beforeWrite = null;

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

	private function createDatabase(): TagDatabase {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(fn (): string => tempnam($this->dir, 'tmp'));
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($this->createMock(ICache::class));

		return new TagDatabase($tempManager, $cacheFactory, $this->createMock(ILockingProvider::class));
	}

	private function createFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturnCallback(fn (): string => 'etag-' . $this->etagCounter);
		$file->method('getSize')->willReturnCallback(fn (): int => (int)filesize($this->storedPath));
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

	public function testConcurrentChangeIsRetried(): void {
		$this->createStoredDatabase();
		$calls = 0;
		$snapshot = $this->createDatabase()->modify($this->createFolder(), function (TagWriter $writer) use (&$calls): void {
			$calls++;
			$writer->createTag('Tag ' . $calls, 0);
			if ($calls === 1) {
				// Another client uploads notes.sqlite while the changes are applied
				$this->etagCounter++;
			}
		});

		$this->assertSame(2, $calls);
		$this->assertSame(1, $this->writes);
		$this->assertSame(['Tag 2'], array_column($snapshot->tags, 'name'));
	}
}
