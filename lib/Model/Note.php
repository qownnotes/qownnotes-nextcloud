<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Model;

use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Lock\LockedException;

/**
 * A note file inside the note folder
 */
class Note {
	private ?string $content = null;

	public function __construct(
		private File $file,
		private string $subFolderPath,
		private bool $favorite,
	) {
	}

	public function getFile(): File {
		return $this->file;
	}

	/**
	 * File ID, used as note ID by the Notes API
	 */
	public function getId(): int {
		return (int)$this->file->getId();
	}

	/**
	 * File name including the suffix, e.g. "Meeting.md" (the "note_file_name" in notes.sqlite)
	 */
	public function getFileName(): string {
		return $this->file->getName();
	}

	/**
	 * Note name, which is the file name without the suffix
	 */
	public function getTitle(): string {
		return pathinfo($this->file->getName(), PATHINFO_FILENAME);
	}

	/**
	 * Subfolder path relative to the note folder, "" for the root (the "note_sub_folder_path" in notes.sqlite,
	 * the "category" in the Notes API)
	 */
	public function getSubFolderPath(): string {
		return $this->subFolderPath;
	}

	public function getModified(): int {
		return $this->file->getMTime();
	}

	public function getContent(): string {
		if ($this->content === null) {
			try {
				$content = $this->file->getContent();
			} catch (GenericFileException $e) {
				// Nextcloud can't read a file while it is written by another request, which isn't reported as a
				// locked file; as LockedException, the API retries the request and finally answers with 423 Locked
				throw new LockedException($this->file->getPath(), $e);
			}
			// Notes are text files, so invalid UTF-8 is replaced instead of breaking the JSON responses
			$this->content = mb_check_encoding($content, 'UTF-8') ? $content : (string)mb_convert_encoding($content, 'UTF-8', 'UTF-8');
		}

		return $this->content;
	}

	public function isFavorite(): bool {
		return $this->favorite;
	}

	public function isReadonly(): bool {
		return !$this->file->isUpdateable();
	}

	public function getFileEtag(): string {
		return $this->file->getEtag();
	}

	public function getSize(): int {
		return (int)$this->file->getSize();
	}
}
