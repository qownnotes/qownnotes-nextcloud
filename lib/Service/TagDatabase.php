<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\NotWritableException;
use OCA\QOwnNotes\Exception\PreconditionFailedException;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCA\QOwnNotes\Exception\TagDatabaseException;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Model\TagSnapshot;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use PDO;
use PDOException;

/**
 * Safe access to the QOwnNotes note folder database "notes.sqlite", following the rules of QOwnNotes Android:
 * only a private copy is ever opened, the file must be an intact rollback-journal (non-WAL) SQLite database with
 * a known schema, all changes of a request are applied in one transaction and the file is only written back if
 * something changed and nobody else changed it in the meantime
 */
class TagDatabase {
	/** QOwnNotes Desktop note folder schema versions whose tag tables can be modified */
	public const WRITABLE_SCHEMA_VERSIONS = [15, 16];

	/** The oldest schema whose tag tables have all needed columns */
	public const MINIMUM_SCHEMA_VERSION = 15;

	/** Schema version of newly created databases */
	public const CREATE_SCHEMA_VERSION = 16;

	public const MAX_SIZE = 50 * 1024 * 1024;

	private const REQUIRED_COLUMNS = [
		'appData' => ['name', 'value'],
		'tag' => ['id', 'name', 'priority', 'parent_id', 'color', 'dark_color', 'updated'],
		'noteTagLink' => ['id', 'tag_id', 'note_file_name', 'note_sub_folder_path', 'created', 'stale_date'],
	];

	/** DDL of the QOwnNotes Desktop note folder schema version 16 (DatabaseService::repairNoteFolderSchema) */
	public const CREATE_SCHEMA = [
		'CREATE TABLE appData (name VARCHAR(255) PRIMARY KEY, value VARCHAR(255))',
		'CREATE TABLE tag (id INTEGER PRIMARY KEY, name VARCHAR(255) COLLATE NOCASE, priority INTEGER DEFAULT 0, created DATETIME DEFAULT current_timestamp, parent_id INTEGER DEFAULT 0, color VARCHAR(20), dark_color VARCHAR(20), updated DATETIME DEFAULT current_timestamp)',
		'CREATE INDEX idxTagParent ON tag( parent_id )',
		'CREATE UNIQUE INDEX idxUniqueTag ON tag (name, parent_id)',
		'CREATE TABLE noteTagLink (id INTEGER PRIMARY KEY, tag_id INTEGER, note_file_name VARCHAR(255) DEFAULT \'\', note_sub_folder_path TEXT DEFAULT \'\', created DATETIME DEFAULT current_timestamp, stale_date DATETIME DEFAULT NULL)',
		'CREATE UNIQUE INDEX idxUniqueTagNoteLink ON noteTagLink (tag_id, note_file_name, note_sub_folder_path)',
		'CREATE TABLE trashItem (id INTEGER PRIMARY KEY, file_name VARCHAR(255), file_size INTEGER, note_sub_folder_path_data TEXT, created DATETIME DEFAULT current_timestamp)',
	];

	private ?ICache $cache = null;

	public function __construct(
		private ITempManager $tempManager,
		private ICacheFactory $cacheFactory,
		private ILockingProvider $lockingProvider,
	) {
	}

	public static function isAvailable(): bool {
		return extension_loaded('pdo_sqlite');
	}

	/**
	 * Reads the tag data of the note folder; a missing database results in an empty snapshot
	 */
	public function read(Folder $notesFolder): TagSnapshot {
		$this->ensureAvailable();
		$file = $this->getFile($notesFolder);
		if ($file === null) {
			return new TagSnapshot(null, null, true, [], []);
		}

		$cacheKey = $file->getId() . '-' . $file->getEtag();
		$cached = $this->getCache()->get($cacheKey);
		if (is_array($cached)) {
			return TagSnapshot::fromArray($cached);
		}

		$path = $this->copyToTemp($file);
		try {
			$pdo = $this->open($path, false);
			$version = $this->validate($pdo);
			$snapshot = $this->readSnapshot($pdo, $file->getEtag(), $version);
		} finally {
			unset($pdo);
			$this->removeTemp($path);
		}

		$this->getCache()->set($cacheKey, $snapshot->toArray(), 3600);
		return $snapshot;
	}

	/**
	 * Applies changes to the tag tables in one transaction and stores the database if anything changed
	 *
	 * @param callable(TagWriter): void $modify
	 * @param string|null $ifMatch expected ETag of notes.sqlite (If-Match header), null to skip the check
	 * @param bool $create whether a missing database may be created
	 */
	public function modify(Folder $notesFolder, callable $modify, ?string $ifMatch = null, bool $create = true): TagSnapshot {
		$this->ensureAvailable();
		$lockKey = 'qownnotes/notes.sqlite/' . $notesFolder->getId();
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'notes.sqlite');
		try {
			// Retry once if another client wrote the file while the changes were applied
			for ($attempt = 1; ; $attempt++) {
				$result = $this->tryModify($notesFolder, $modify, $ifMatch, $create);
				if ($result !== null) {
					return $result;
				}
				if ($attempt >= 2) {
					throw new PreconditionFailedException([], 'notes.sqlite was changed in the meantime');
				}
			}
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return TagSnapshot|null null if the file was changed by someone else while it was modified
	 */
	private function tryModify(Folder $notesFolder, callable $modify, ?string $ifMatch, bool $create): ?TagSnapshot {
		$file = $this->getFile($notesFolder);
		$etag = $file?->getEtag();

		if ($ifMatch !== null && trim($ifMatch) !== '' && ($etag === null || !ApiResponder::etagMatches($ifMatch, $etag))) {
			throw new PreconditionFailedException(['etag' => $etag], 'notes.sqlite was changed in the meantime');
		}

		if ($file === null) {
			if (!$create) {
				throw new TagDatabaseException('The note folder has no notes.sqlite database');
			}
			if (!$notesFolder->isCreatable()) {
				throw new NotWritableException('notes.sqlite can not be created');
			}
			$path = $this->createTempDatabase();
		} else {
			if (!$file->isUpdateable()) {
				throw new NotWritableException('notes.sqlite is read-only');
			}
			$path = $this->copyToTemp($file);
		}

		$pdo = null;
		$writer = null;
		try {
			$pdo = $this->open($path, true);
			$version = $this->validate($pdo);
			if (!in_array($version, self::WRITABLE_SCHEMA_VERSIONS, true)) {
				throw new TagDatabaseException('notes.sqlite uses database version ' . $version . ', which can not be modified');
			}

			$writer = new TagWriter($pdo);
			$pdo->beginTransaction();
			try {
				$modify($writer);
				$pdo->commit();
			} catch (\Throwable $e) {
				$pdo->rollBack();
				throw $e;
			}

			$changed = $writer->getTotalChanges() > 0;
			// Close the database before the file is checked and stored
			$writer = null;
			$pdo = null;
			$this->removeTemp($path . '-journal');
			$this->checkHeader($path);

			if (!$changed) {
				return $this->read($notesFolder);
			}

			// Optimistic concurrency against writers outside of this app (WebDAV, desktop sync)
			$current = $this->getFile($notesFolder);
			if ($current?->getEtag() !== $etag) {
				return null;
			}

			$this->store($notesFolder, $current, $path);
		} catch (PDOException $e) {
			throw new TagDatabaseException('notes.sqlite can not be modified', $e);
		} finally {
			$writer = null;
			$pdo = null;
			$this->removeTemp($path);
		}

		return $this->read($notesFolder);
	}

	private function store(Folder $notesFolder, ?File $file, string $path): void {
		$handle = fopen($path, 'rb');
		if ($handle === false) {
			throw new TagDatabaseException('The modified notes.sqlite can not be read');
		}

		try {
			if ($file === null) {
				$notesFolder->newFile(NoteFolderService::TAG_DATABASE_FILE_NAME, $handle);
			} else {
				$previousEtag = $file->getEtag();
				$previousMtime = $file->getMTime();
				$file->putContent($handle);

				// Storages like the local one derive the ETag from the modification time and size, so two changes
				// within the same second could keep the ETag, and other clients and the cache would miss the change
				$stored = $this->getFile($notesFolder);
				if ($stored !== null && $stored->getEtag() === $previousEtag) {
					$stored->touch(max(time(), $previousMtime) + 1);
				}
			}
		} catch (NotPermittedException $e) {
			throw new NotWritableException('notes.sqlite can not be written', $e);
		} finally {
			// putContent() may already have closed the stream
			/** @psalm-suppress RedundantCondition */
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	private function getFile(Folder $notesFolder): ?File {
		if (!$notesFolder->nodeExists(NoteFolderService::TAG_DATABASE_FILE_NAME)) {
			return null;
		}

		$node = $notesFolder->get(NoteFolderService::TAG_DATABASE_FILE_NAME);
		if (!$node instanceof File) {
			throw new TagDatabaseException('notes.sqlite is not a file');
		}

		return $node;
	}

	private function copyToTemp(File $file): string {
		if ($file->getSize() > self::MAX_SIZE) {
			throw new TagDatabaseException('notes.sqlite is too large');
		}

		$path = $this->getTempPath();
		$source = false;
		$target = false;
		try {
			$source = $file->fopen('rb');
			$target = fopen($path, 'wb');
			if ($source === false || $target === false) {
				throw new TagDatabaseException('notes.sqlite can not be read');
			}

			// Storage metadata can be stale or inaccurate. Bound the actual copy as well.
			$copied = stream_copy_to_stream($source, $target, self::MAX_SIZE + 1);
			if ($copied === false) {
				throw new TagDatabaseException('notes.sqlite can not be read');
			}
			if ($copied > self::MAX_SIZE) {
				throw new TagDatabaseException('notes.sqlite is too large');
			}
			if (!fflush($target)) {
				throw new TagDatabaseException('notes.sqlite can not be read');
			}
			$this->checkHeader($path);
			return $path;
		} catch (\Throwable $e) {
			$this->removeTemp($path);
			throw $e;
		} finally {
			if (is_resource($source)) {
				fclose($source);
			}
			if (is_resource($target)) {
				fclose($target);
			}
		}
	}

	private function createTempDatabase(): string {
		$path = $this->getTempPath();
		$pdo = $this->open($path, true);
		$pdo->beginTransaction();
		foreach (self::CREATE_SCHEMA as $statement) {
			$pdo->exec($statement);
		}
		$pdo->prepare('INSERT INTO appData (name, value) VALUES (?, ?)')->execute(['database_version', (string)self::CREATE_SCHEMA_VERSION]);
		$pdo->commit();
		unset($pdo);

		return $path;
	}

	private function getTempPath(): string {
		$path = $this->tempManager->getTemporaryFile('.sqlite');
		if ($path === false) {
			throw new TagDatabaseException('No temporary file can be created');
		}

		return $path;
	}

	private function open(string $path, bool $writable): PDO {
		try {
			$pdo = new PDO('sqlite:' . $path, null, null, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::SQLITE_ATTR_OPEN_FLAGS => $writable ? PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE : PDO::SQLITE_OPEN_READONLY,
			]);
			if ($writable) {
				// Keep the rollback-journal format; a WAL file would need sidecar files that are never synced
				$pdo->exec('PRAGMA journal_mode = DELETE');
			}
			return $pdo;
		} catch (PDOException $e) {
			throw new TagDatabaseException('notes.sqlite can not be opened', $e);
		}
	}

	/**
	 * @return int the schema version
	 */
	private function validate(PDO $pdo): int {
		try {
			if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
				throw new TagDatabaseException('notes.sqlite is damaged');
			}

			foreach (self::REQUIRED_COLUMNS as $table => $columns) {
				$present = array_map('strtolower', $pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_COLUMN, 1));
				$missing = array_diff(array_map(static fn (string $column): string => strtolower($column), $columns), $present);
				if ($missing !== []) {
					throw new TagDatabaseException('notes.sqlite is missing ' . $table . ' columns: ' . implode(', ', $missing));
				}
			}

			$version = $pdo->query("SELECT value FROM appData WHERE name = 'database_version'")->fetchColumn();
		} catch (PDOException $e) {
			throw new TagDatabaseException('notes.sqlite can not be read', $e);
		}

		if ($version === false || !is_numeric(trim((string)$version))) {
			throw new TagDatabaseException('notes.sqlite has no database version');
		}
		$version = (int)trim((string)$version);
		if ($version < self::MINIMUM_SCHEMA_VERSION) {
			throw new TagDatabaseException('notes.sqlite uses the outdated database version ' . $version);
		}

		return $version;
	}

	private function readSnapshot(PDO $pdo, string $etag, int $version): TagSnapshot {
		$tags = [];
		foreach ($pdo->query('SELECT id, name, priority, parent_id, color, dark_color, updated FROM tag ORDER BY priority ASC, name ASC') as $row) {
			$tags[(int)$row['id']] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'priority' => (int)$row['priority'],
				'parentId' => (int)$row['parent_id'],
				'color' => $row['color'] === null || $row['color'] === '' ? null : (string)$row['color'],
				'darkColor' => $row['dark_color'] === null || $row['dark_color'] === '' ? null : (string)$row['dark_color'],
				'updated' => $row['updated'] === null ? null : (string)$row['updated'],
			];
		}

		$links = [];
		foreach ($pdo->query('SELECT id, tag_id, note_file_name, note_sub_folder_path, stale_date FROM noteTagLink WHERE tag_id IS NOT NULL AND note_file_name IS NOT NULL ORDER BY id') as $row) {
			$links[] = [
				'id' => (int)$row['id'],
				'tagId' => (int)$row['tag_id'],
				'fileName' => (string)$row['note_file_name'],
				'subFolderPath' => (string)($row['note_sub_folder_path'] ?? ''),
				'stale' => $row['stale_date'] !== null,
			];
		}

		return new TagSnapshot($etag, $version, in_array($version, self::WRITABLE_SCHEMA_VERSIONS, true), $tags, $links);
	}

	/**
	 * Bytes 18 and 19 of the SQLite header are the write and read format versions: 1 is a rollback journal, 2 is WAL
	 */
	private function checkHeader(string $path): void {
		$header = (string)file_get_contents($path, false, null, 0, 100);
		if (strlen($header) < 100 || !str_starts_with($header, "SQLite format 3\0")) {
			throw new TagDatabaseException('notes.sqlite is not an SQLite database');
		}
		if (ord($header[18]) !== 1 || ord($header[19]) !== 1) {
			throw new TagDatabaseException('notes.sqlite uses write-ahead logging, which is not supported');
		}
	}

	private function removeTemp(string $path): void {
		if (is_file($path)) {
			unlink($path);
		}
	}

	private function ensureAvailable(): void {
		if (!self::isAvailable()) {
			throw new ServiceUnavailableException('Tags need the PHP extension pdo_sqlite');
		}
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createLocal('qownnotes-tags');
	}
}
