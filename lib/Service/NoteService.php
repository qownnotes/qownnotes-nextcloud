<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InsufficientStorageException;
use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Exception\NotWritableException;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Model\NoteLocation;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Notes are the note files inside the note folder and its (non-ignored) subfolders
 */
class NoteService {
	public function __construct(
		private NoteFolderService $folders,
		private SettingsService $settings,
		private FavoriteService $favorites,
	) {
	}

	/**
	 * @return array<int, Note> all notes, keyed by note ID
	 */
	public function getAll(string $userId, ?string $subFolderPath = null, bool $recursive = true): array {
		$notesFolder = $this->folders->getNotesFolder($userId);
		$favoriteIds = $this->favorites->getFavoriteIds($userId);
		$includeSubFolders = $this->settings->isSubfoldersEnabled($userId);

		$startFolder = $notesFolder;
		$startPath = '';
		if ($subFolderPath !== null && $subFolderPath !== '') {
			$startPath = SettingsService::normalizePath($subFolderPath);
			if (!$includeSubFolders || $this->folders->isIgnoredSubFolderPath($userId, $startPath)) {
				return [];
			}

			try {
				$startFolder = $notesFolder->get($startPath);
			} catch (NotFoundException) {
				return [];
			}

			if (!$startFolder instanceof Folder) {
				return [];
			}
		}

		$notes = [];
		$this->collectNotes($userId, $startFolder, $startPath, $favoriteIds, $includeSubFolders && $recursive, $notes);
		return $notes;
	}

	/**
	 * @throws NoteNotFoundException
	 */
	public function get(string $userId, int $id): Note {
		$notesFolder = $this->folders->getNotesFolder($userId);
		$node = $notesFolder->getFirstNodeById($id);
		if (!$node instanceof File || !$this->folders->isNoteFileName($userId, $node->getName())) {
			throw new NoteNotFoundException();
		}

		$subFolderPath = $this->folders->getSubFolderPath($notesFolder, $node);
		if ($subFolderPath !== '' && (!$this->settings->isSubfoldersEnabled($userId)
			|| $this->folders->isIgnoredSubFolderPath($userId, $subFolderPath))) {
			throw new NoteNotFoundException();
		}

		return new Note($node, $subFolderPath, isset($this->favorites->getFavoriteIds($userId)[$id]));
	}

	/**
	 * Creates a note; the title is sanitized and made unique inside the subfolder
	 */
	public function create(string $userId, string $title, string $subFolderPath, string $content = ''): Note {
		$notesFolder = $this->folders->getNotesFolder($userId);
		$folder = $this->folders->getSubFolder($userId, $notesFolder, $subFolderPath, true);
		$this->ensureCreatable($folder);
		$this->ensureSufficientStorage($folder, strlen($content));

		$fileName = $this->getUniqueFileName($folder, $this->sanitizeTitle($title), $this->settings->getFileSuffix($userId));
		try {
			$file = $folder->newFile($fileName, $content);
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The note can not be created', $e);
		} catch (NotEnoughSpaceException $e) {
			throw new InsufficientStorageException(previous: $e);
		}

		return new Note($file, $this->folders->getSubFolderPath($notesFolder, $file), false);
	}

	public function setContent(Note $note, string $content): Note {
		$this->ensureWritable($note);
		$file = $note->getFile();
		$this->ensureSufficientStorage($file->getParent(), strlen($content) - $note->getSize());

		try {
			$file->putContent($content);
		} catch (NotPermittedException $e) {
			throw new NotWritableException(previous: $e);
		} catch (NotEnoughSpaceException $e) {
			throw new InsufficientStorageException(previous: $e);
		}

		return new Note($file, $note->getSubFolderPath(), $note->isFavorite());
	}

	public function setModified(Note $note, int $modified): Note {
		$this->ensureWritable($note);
		$note->getFile()->touch($modified);
		return new Note($note->getFile(), $note->getSubFolderPath(), $note->isFavorite());
	}

	public function setFavorite(string $userId, Note $note, bool $favorite): Note {
		$this->favorites->setFavorite($userId, $note->getId(), $favorite);
		return new Note($note->getFile(), $note->getSubFolderPath(), $favorite);
	}

	/**
	 * Renames and/or moves a note; null keeps the current title or subfolder
	 *
	 * @return array{note: Note, from: NoteLocation, to: NoteLocation}
	 */
	public function move(string $userId, Note $note, ?string $title, ?string $subFolderPath): array {
		$from = NoteLocation::fromNote($note);
		$notesFolder = $this->folders->getNotesFolder($userId);
		$file = $note->getFile();

		$targetFolder = $subFolderPath === null
			? $file->getParent()
			: $this->folders->getSubFolder($userId, $notesFolder, $subFolderPath, true);
		$targetTitle = $title === null ? $note->getTitle() : $this->sanitizeTitle($title);
		$extension = pathinfo($note->getFileName(), PATHINFO_EXTENSION);
		$suffix = $extension === '' ? '' : '.' . $extension;

		$isSameFolder = $targetFolder->getId() === $file->getParent()->getId();
		if ($isSameFolder && $targetTitle . $suffix === $note->getFileName()) {
			return ['note' => $note, 'from' => $from, 'to' => $from];
		}

		$this->ensureWritable($note);
		$fileName = $this->getUniqueFileName($targetFolder, $targetTitle, $suffix, $isSameFolder ? $note->getFileName() : null);

		try {
			$moved = $file->move($targetFolder->getPath() . '/' . $fileName);
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The note can not be moved', $e);
		}

		if (!$moved instanceof File) {
			throw new NoteNotFoundException();
		}

		$movedNote = new Note($moved, $this->folders->getSubFolderPath($notesFolder, $moved), $note->isFavorite());
		return ['note' => $movedNote, 'from' => $from, 'to' => NoteLocation::fromNote($movedNote)];
	}

	/**
	 * Deletes a note; it is moved to the Nextcloud trash if the trash bin app is enabled
	 */
	public function delete(Note $note): void {
		if (!$note->getFile()->isDeletable()) {
			throw new NotWritableException('The note can not be deleted');
		}

		try {
			$note->getFile()->delete();
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The note can not be deleted', $e);
		}
	}

	public function sanitizeTitle(string $title): string {
		$title = NoteTitle::sanitize($title);
		return $title === '' ? NoteTitle::DEFAULT_TITLE : $title;
	}

	/**
	 * Finds a free file name like QOwnNotes Desktop: "Title.md", "Title 1.md", "Title 2.md", ...;
	 * names are compared case-insensitively to avoid problems on case-insensitive filesystems
	 *
	 * @param string|null $ownFileName current file name of the note that is renamed, which doesn't count as conflict
	 */
	public function getUniqueFileName(Folder $folder, string $title, string $suffix, ?string $ownFileName = null): string {
		$existing = [];
		foreach ($folder->getDirectoryListing() as $node) {
			$existing[mb_strtolower($node->getName(), 'UTF-8')] = true;
		}
		if ($ownFileName !== null) {
			unset($existing[mb_strtolower($ownFileName, 'UTF-8')]);
		}

		$candidate = $title . $suffix;
		for ($counter = 1; isset($existing[mb_strtolower($candidate, 'UTF-8')]); $counter++) {
			$candidate = $title . ' ' . $counter . $suffix;
		}

		return $candidate;
	}

	/**
	 * @param array<int, true> $favoriteIds
	 * @param array<int, Note> $notes
	 */
	private function collectNotes(string $userId, Folder $folder, string $subFolderPath, array $favoriteIds, bool $recursive, array &$notes): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if ($node->getType() === FileInfo::TYPE_FOLDER) {
				if ($recursive && $node instanceof Folder && !$this->folders->isIgnoredFolderName($userId, $name)) {
					$path = $subFolderPath === '' ? $name : $subFolderPath . '/' . $name;
					$this->collectNotes($userId, $node, $path, $favoriteIds, true, $notes);
				}
				continue;
			}

			if ($node instanceof File && $this->folders->isNoteFileName($userId, $name)) {
				$id = (int)$node->getId();
				$notes[$id] = new Note($node, $subFolderPath, isset($favoriteIds[$id]));
			}
		}
	}

	private function ensureWritable(Note $note): void {
		if ($note->isReadonly()) {
			throw new NotWritableException();
		}
	}

	private function ensureCreatable(Folder $folder): void {
		if (!$folder->isCreatable()) {
			throw new NotWritableException('Notes can not be created in this folder');
		}
	}

	private function ensureSufficientStorage(Folder $folder, int $requiredBytes): void {
		$freeSpace = $folder->getFreeSpace();
		// Negative values mean unknown or unlimited free space
		if ($requiredBytes > 0 && $freeSpace >= 0 && $freeSpace < $requiredBytes) {
			throw new InsufficientStorageException();
		}
	}

	/**
	 * @throws InvalidInputException
	 */
	public function validateSubFolderPath(string $userId, string $subFolderPath): string {
		$subFolderPath = SettingsService::normalizePath($subFolderPath);
		if ($this->folders->isIgnoredSubFolderPath($userId, $subFolderPath)) {
			throw new InvalidInputException('The subfolder name is reserved or ignored');
		}

		return $subFolderPath;
	}
}
