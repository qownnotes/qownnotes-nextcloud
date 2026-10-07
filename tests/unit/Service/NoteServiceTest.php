<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Service\FavoriteService;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

class NoteServiceTest extends TestCase {
	private NoteService $service;

	protected function setUp(): void {
		$this->service = new NoteService(
			$this->createMock(NoteFolderService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(FavoriteService::class),
		);
	}

	private function createFolder(array $names): Folder {
		$nodes = array_map(function (string $name): Node {
			$node = $this->createMock(Node::class);
			$node->method('getName')->willReturn($name);
			return $node;
		}, $names);

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($nodes);
		return $folder;
	}

	public function testUniqueFileNameWithoutConflict(): void {
		$this->assertSame('Title.md', $this->service->getUniqueFileName($this->createFolder(['Other.md']), 'Title', '.md'));
	}

	public function testUniqueFileNameUsesQOwnNotesNumbering(): void {
		$folder = $this->createFolder(['Title.md', 'title 1.md', 'Title 2.MD']);
		$this->assertSame('Title 3.md', $this->service->getUniqueFileName($folder, 'Title', '.md'));
	}

	public function testUniqueFileNameIgnoresOwnFile(): void {
		$folder = $this->createFolder(['title.md']);
		$this->assertSame('Title.md', $this->service->getUniqueFileName($folder, 'Title', '.md', 'title.md'));
	}

	public function testSanitizeTitleFallsBackToDefault(): void {
		$this->assertSame('Note', $this->service->sanitizeTitle('///'));
	}

	public function testInternalPathIsRelativeToRequestingUser(): void {
		$this->assertSame('/Archive/Notes/Work/Meeting.note', $this->internalPath('Archive/Notes/Work/Meeting.note'));
	}

	public function testInternalPathRejectsFilesOutsideUserFolder(): void {
		$this->expectException(NoteNotFoundException::class);
		$this->internalPath(null);
	}

	private function internalPath(?string $relativePath): string {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/Archive/Notes/Work/Meeting.note');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())->method('getRelativePath')
			->with('/alice/files/Archive/Notes/Work/Meeting.note')->willReturn($relativePath);
		$folders = $this->createMock(NoteFolderService::class);
		$folders->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$service = new NoteService($folders, $this->createMock(SettingsService::class), $this->createMock(FavoriteService::class));

		return $service->getInternalPath('alice', new Note($file, 'Work', false));
	}
}
