<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Exception\NotWritableException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Note subfolders like in QOwnNotes Desktop: real folders below the note folder, including empty ones
 */
class SubFolderService {
	public function __construct(
		private NoteFolderService $folders,
		private SettingsService $settings,
		private TagService $tagService,
	) {
	}

	/**
	 * @return array{path: string, name: string, noteCount: int, noteCountRecursive: int, readonly: bool, mtime: int, children: list<array>}
	 */
	public function getTree(string $userId): array {
		$notesFolder = $this->folders->getNotesFolder($userId);
		return $this->buildNode($userId, $notesFolder, '', $this->settings->isSubfoldersEnabled($userId));
	}

	public function create(string $userId, string $path): array {
		$path = $this->validatePath($userId, $path);
		$notesFolder = $this->folders->getNotesFolder($userId);
		if ($notesFolder->nodeExists($path)) {
			throw new InvalidInputException('The subfolder already exists');
		}

		$folder = $this->folders->getSubFolder($userId, $notesFolder, $path, true);
		return $this->buildNode($userId, $folder, $this->folders->getSubFolderPath($notesFolder, $folder), true);
	}

	/**
	 * Renames or moves a subfolder and moves the tag links of its notes in notes.sqlite
	 *
	 * @return array{folder: array, tagsRelinked: bool}
	 */
	public function move(string $userId, string $path, string $newPath): array {
		$path = $this->validatePath($userId, $path);
		$newPath = $this->validatePath($userId, $newPath);
		if ($newPath === $path) {
			throw new InvalidInputException('The new path is the same as the old path');
		}
		if (str_starts_with($newPath . '/', $path . '/')) {
			throw new InvalidInputException('A subfolder can not be moved into itself');
		}

		$notesFolder = $this->folders->getNotesFolder($userId);
		$folder = $this->getExistingFolder($notesFolder, $path);
		if ($notesFolder->nodeExists($newPath) && mb_strtolower($newPath, 'UTF-8') !== mb_strtolower($path, 'UTF-8')) {
			throw new InvalidInputException('The target subfolder already exists');
		}

		$parentPath = dirname($newPath);
		$parent = $parentPath === '.' ? $notesFolder : $this->folders->getSubFolder($userId, $notesFolder, $parentPath, true);
		$name = NoteTitle::sanitize(basename($newPath));
		if ($name === '') {
			throw new InvalidInputException('The folder name is invalid');
		}

		try {
			$moved = $folder->move($parent->getPath() . '/' . $name);
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The subfolder can not be moved', $e);
		}
		if (!$moved instanceof Folder) {
			throw new NoteNotFoundException('The subfolder does not exist');
		}

		$movedPath = $this->folders->getSubFolderPath($notesFolder, $moved);
		return [
			'folder' => $this->buildNode($userId, $moved, $movedPath, true),
			'tagsRelinked' => $this->tagService->relinkSubFolder($userId, $path, $movedPath),
		];
	}

	/**
	 * Deletes a subfolder (to the trash if the trash bin app is enabled); the tag links of its notes become stale,
	 * so QOwnNotes keeps them for 10 days in case the folder is restored
	 *
	 * @return array{tagsUpdated: bool}
	 */
	public function delete(string $userId, string $path): array {
		$path = $this->validatePath($userId, $path);
		$folder = $this->getExistingFolder($this->folders->getNotesFolder($userId), $path);
		if (!$folder->isDeletable()) {
			throw new NotWritableException('The subfolder can not be deleted');
		}

		try {
			$folder->delete();
		} catch (NotPermittedException $e) {
			throw new NotWritableException('The subfolder can not be deleted', $e);
		}

		return ['tagsUpdated' => $this->tagService->markSubFolderStale($userId, $path)];
	}

	private function validatePath(string $userId, string $path): string {
		$path = SettingsService::normalizePath($path);
		if ($path === '') {
			throw new InvalidInputException('The root of the note folder can not be changed');
		}
		if ($this->folders->isIgnoredSubFolderPath($userId, $path)) {
			throw new InvalidInputException('The subfolder name is reserved or ignored');
		}

		return $path;
	}

	private function getExistingFolder(Folder $notesFolder, string $path): Folder {
		try {
			$folder = $notesFolder->get($path);
		} catch (NotFoundException $e) {
			throw new NoteNotFoundException('The subfolder does not exist', $e);
		}
		if (!$folder instanceof Folder) {
			throw new NoteNotFoundException('The subfolder does not exist');
		}

		return $folder;
	}

	private function buildNode(string $userId, Folder $folder, string $path, bool $recursive): array {
		$noteCount = 0;
		$children = [];
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if ($node instanceof Folder) {
				if ($recursive && !$this->folders->isIgnoredFolderName($userId, $name)) {
					$children[] = $this->buildNode($userId, $node, $path === '' ? $name : $path . '/' . $name, true);
				}
			} elseif ($node instanceof File && $this->folders->isNoteFileName($userId, $name)) {
				$noteCount++;
			}
		}
		usort($children, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return [
			'path' => $path,
			'name' => $path === '' ? '' : $folder->getName(),
			'noteCount' => $noteCount,
			'noteCountRecursive' => $noteCount + (int)array_sum(array_column($children, 'noteCountRecursive')),
			'readonly' => !$folder->isCreatable(),
			'mtime' => $folder->getMTime(),
			'children' => $children,
		];
	}
}
