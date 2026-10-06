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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Media files and attachments are stored like QOwnNotes Desktop does: in the "media" and "attachments" folders
 * at the root of the note folder, linked relatively from the notes
 */
class AttachmentService {
	public const MEDIA_FOLDER = 'media';
	public const ATTACHMENTS_FOLDER = 'attachments';

	public function __construct(
		private NoteFolderService $folders,
		private IFilenameValidator $filenameValidator,
	) {
	}

	/**
	 * Resolves a link of a note (relative to the note's folder) to a file inside the note folder
	 *
	 * @throws NoteNotFoundException if the file doesn't exist or is outside the note folder
	 */
	public function get(string $userId, Note $note, string $path): File {
		$notesFolder = $this->folders->getNotesFolder($userId);
		$relativePath = self::resolveRelativePath($note->getSubFolderPath(), $path);
		if ($relativePath === null || $relativePath === '') {
			throw new NoteNotFoundException('The attachment does not exist');
		}

		try {
			$node = $notesFolder->get($relativePath);
		} catch (NotFoundException $e) {
			throw new NoteNotFoundException('The attachment does not exist', $e);
		}

		if (!$node instanceof File) {
			throw new NoteNotFoundException('The attachment does not exist');
		}

		return $node;
	}

	/**
	 * Stores an uploaded file in the media folder (images) or attachments folder (other files)
	 *
	 * @return string the link to the file, relative to the note's folder
	 */
	public function create(string $userId, Note $note, string $fileName, string $mimeType, string $content): string {
		$fileName = trim(basename(str_replace('\\', '/', $fileName)));
		if ($fileName === '' || !$this->filenameValidator->isFilenameValid($fileName)) {
			throw new InvalidInputException('The file name is invalid');
		}

		$folderName = str_starts_with($mimeType, 'image/') ? self::MEDIA_FOLDER : self::ATTACHMENTS_FOLDER;
		$notesFolder = $this->folders->getNotesFolder($userId);
		$folder = $this->getOrCreateFolder($notesFolder, $folderName);
		$uniqueName = $this->getUniqueFileName($folder, $fileName);

		try {
			$folder->newFile($uniqueName, $content);
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The attachment can not be stored', $e);
		} catch (NotEnoughSpaceException $e) {
			throw new InsufficientStorageException(previous: $e);
		}

		return self::getRelativeLink($note->getSubFolderPath(), $folderName . '/' . $uniqueName);
	}

	/**
	 * Deletes a media file or attachment; other files of the note folder can't be deleted this way
	 */
	public function delete(string $userId, Note $note, string $path): void {
		$file = $this->get($userId, $note, $path);
		$notesFolder = $this->folders->getNotesFolder($userId);
		$relativePath = (string)$notesFolder->getRelativePath($file->getPath());
		$topFolder = explode('/', trim($relativePath, '/'))[0];

		if (!in_array($topFolder, [self::MEDIA_FOLDER, self::ATTACHMENTS_FOLDER], true)) {
			throw new NotWritableException('Only media files and attachments can be deleted');
		}

		try {
			$file->delete();
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The attachment can not be deleted', $e);
		}
	}

	/**
	 * Resolves a link relative to a subfolder to a path relative to the note folder
	 *
	 * @return string|null null if the link points outside the note folder
	 */
	public static function resolveRelativePath(string $subFolderPath, string $link): ?string {
		$link = rawurldecode(str_replace('\\', '/', $link));
		// Strip a "file://" prefix and query or fragment parts that links in Markdown may have
		$link = (string)preg_replace('/[?#].*$/', '', (string)preg_replace('#^file://#', '', $link));

		$segments = str_starts_with($link, '/') || $subFolderPath === '' ? [] : explode('/', $subFolderPath);
		foreach (explode('/', $link) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				if ($segments === []) {
					return null;
				}
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

	/**
	 * Creates a link from a note in a subfolder to a path relative to the note folder, e.g. "../media/image.png"
	 */
	public static function getRelativeLink(string $subFolderPath, string $targetPath): string {
		$depth = $subFolderPath === '' ? 0 : count(explode('/', $subFolderPath));
		return str_repeat('../', $depth) . $targetPath;
	}

	private function getOrCreateFolder(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if (!$node instanceof Folder) {
				throw new InvalidInputException('A file is in the way of the "' . $name . '" folder');
			}
			return $node;
		}

		try {
			return $parent->newFolder($name);
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The folder "' . $name . '" can not be created', $e);
		}
	}

	private function getUniqueFileName(Folder $folder, string $fileName): string {
		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$base = pathinfo($fileName, PATHINFO_FILENAME);
		$suffix = $extension === '' ? '' : '.' . $extension;

		$candidate = $fileName;
		for ($counter = 1; $folder->nodeExists($candidate); $counter++) {
			$candidate = $base . '-' . $counter . $suffix;
		}

		return $candidate;
	}
}
