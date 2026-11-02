<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\NotWritableException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Resolves the user's note folder and applies the QOwnNotes rules for note files and subfolders
 */
class NoteFolderService {
	/** Folder names that QOwnNotes Desktop uses internally and never shows as note subfolders */
	public const INTERNAL_FOLDER_NAMES = ['.', '..', 'media', 'attachments', 'trash'];

	/** Note file extensions that are always supported, in addition to the user's file suffix */
	public const DEFAULT_NOTE_EXTENSIONS = ['md', 'txt'];

	public const TAG_DATABASE_FILE_NAME = 'notes.sqlite';

	/** Attempts to get the folder creation lock, 100 ms apart */
	private const CREATE_LOCK_TRIES = 50;

	/** @var array<string, list<string>> compiled ignore patterns per user */
	private array $ignorePatterns = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private SettingsService $settings,
		private ILockingProvider $lockingProvider,
	) {
	}

	public function getUserFolder(string $userId): Folder {
		return $this->rootFolder->getUserFolder($userId);
	}

	/**
	 * @throws InvalidInputException if the configured path is a file
	 * @throws NotFoundException if the folder doesn't exist and $create is false
	 */
	public function getNotesFolder(string $userId, bool $create = true): Folder {
		$userFolder = $this->getUserFolder($userId);
		$path = $this->settings->getNotesPath($userId);

		if (!$userFolder->nodeExists($path)) {
			if (!$create) {
				throw new NotFoundException('The note folder does not exist');
			}

			return $this->createFolderPath($userFolder, $path);
		}

		$node = $userFolder->get($path);
		if (!$node instanceof Folder) {
			throw new InvalidInputException('The note folder path points to a file');
		}

		return $node;
	}

	/**
	 * Returns the subfolder path of a node relative to the note folder ("" for the root, "a/b" for nested folders);
	 * for files, the path of the folder they are in
	 *
	 * @throws NotFoundException if the node isn't inside the note folder
	 */
	public function getSubFolderPath(Folder $notesFolder, Node $node): string {
		$folderPath = $node instanceof Folder ? $node->getPath() : $node->getParent()->getPath();
		$relativePath = $notesFolder->getRelativePath($folderPath);
		if ($relativePath === null) {
			throw new NotFoundException('The node is not inside the note folder');
		}

		return trim($relativePath, '/');
	}

	/**
	 * Whether a folder is hidden from the note subfolders, like in QOwnNotes Desktop
	 * (internal folders and folders matching the user's "ignoreNoteSubFolders" regular expressions)
	 */
	public function isIgnoredFolderName(string $userId, string $name): bool {
		if (in_array($name, self::INTERNAL_FOLDER_NAMES, true)) {
			return true;
		}

		foreach ($this->getIgnorePatterns($userId) as $pattern) {
			if (@preg_match($pattern, $name) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any segment of a subfolder path is ignored
	 */
	public function isIgnoredSubFolderPath(string $userId, string $subFolderPath): bool {
		if ($subFolderPath === '') {
			return false;
		}

		foreach (explode('/', $subFolderPath) as $segment) {
			if ($this->isIgnoredFolderName($userId, $segment)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string> lowercase extensions without a dot
	 */
	public function getNoteExtensions(string $userId): array {
		$extensions = self::DEFAULT_NOTE_EXTENSIONS;
		$suffix = ltrim($this->settings->getFileSuffix($userId), '.');
		if (!in_array($suffix, $extensions, true)) {
			$extensions[] = $suffix;
		}

		return $extensions;
	}

	public function isNoteFileName(string $userId, string $fileName): bool {
		if ($fileName === '' || $fileName[0] === '.') {
			return false;
		}

		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		return in_array($extension, $this->getNoteExtensions($userId), true);
	}

	/**
	 * Returns a subfolder of the note folder, creating missing folders if requested
	 *
	 * @throws InvalidInputException if the path contains ignored folder names
	 * @throws NotFoundException if the folder doesn't exist and $create is false
	 */
	public function getSubFolder(string $userId, Folder $notesFolder, string $subFolderPath, bool $create): Folder {
		$subFolderPath = SettingsService::normalizePath($subFolderPath);
		if ($subFolderPath === '') {
			return $notesFolder;
		}

		if ($this->isIgnoredSubFolderPath($userId, $subFolderPath)) {
			throw new InvalidInputException('The subfolder name is reserved or ignored');
		}

		if (!$notesFolder->nodeExists($subFolderPath)) {
			if (!$create) {
				throw new NotFoundException('The subfolder does not exist');
			}

			return $this->createFolderPath($notesFolder, $subFolderPath);
		}

		$node = $notesFolder->get($subFolderPath);
		if (!$node instanceof Folder) {
			throw new InvalidInputException('The subfolder path points to a file');
		}

		return $node;
	}

	/**
	 * Parallel requests of a fresh account all create the note folder. Nextcloud keeps shared file locks until the
	 * end of a request, so concurrent mkdir calls of the same path block each other and all fail as locked;
	 * an app lock lets only one request create folders, the others find them afterwards.
	 */
	private function createFolderPath(Folder $parent, string $path): Folder {
		$lockKey = 'qownnotes/create-folder/' . $parent->getId();
		$this->acquireLock($lockKey);
		try {
			return $this->createFolderPathUnlocked($parent, $path);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function acquireLock(string $lockKey): void {
		for ($try = 1; ; $try++) {
			try {
				$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'note folder');
				return;
			} catch (LockedException $e) {
				if ($try >= self::CREATE_LOCK_TRIES) {
					throw $e;
				}
				usleep(100000);
			}
		}
	}

	private function createFolderPathUnlocked(Folder $parent, string $path): Folder {
		$folder = $parent;
		foreach (explode('/', $path) as $segment) {
			if (!$folder->nodeExists($segment)) {
				// Like the Notes API, remove characters that are invalid in folder names
				$segment = NoteTitle::sanitize($segment);
				if ($segment === '') {
					throw new InvalidInputException('The folder name is invalid');
				}
			}

			if ($folder->nodeExists($segment)) {
				$node = $folder->get($segment);
				if (!$node instanceof Folder) {
					throw new InvalidInputException('A file is in the way of the folder path');
				}
				$folder = $node;
				continue;
			}

			try {
				$folder = $folder->newFolder($segment);
			} catch (NotPermittedException $e) {
				throw new NotWritableException('The folder can not be created', $e);
			}
		}

		return $folder;
	}

	/**
	 * Converts the user's ";"-separated list of regular expressions (QRegularExpression syntax) to PCRE patterns
	 *
	 * @return list<string>
	 */
	private function getIgnorePatterns(string $userId): array {
		if (!isset($this->ignorePatterns[$userId])) {
			$patterns = [];
			foreach (explode(';', $this->settings->getIgnoreNoteSubFolders($userId)) as $expression) {
				if (trim($expression) === '') {
					continue;
				}

				// Bracket delimiters need no escaping, because PCRE balances nested parentheses
				$pattern = '(' . $expression . ')u';
				// Skip invalid expressions instead of failing, like QOwnNotes Desktop does
				if (@preg_match($pattern, '') !== false) {
					$patterns[] = $pattern;
				}
			}
			$this->ignorePatterns[$userId] = $patterns;
		}

		return $this->ignorePatterns[$userId];
	}
}
