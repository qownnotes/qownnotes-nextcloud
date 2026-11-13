<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\NoteFolderCandidateService;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteFolderCandidateServiceTest extends TestCase {
	private const USER_ROOT = '/alice/files';

	/**
	 * @param array<string, mixed> $tree folder names map to arrays, file names to null
	 */
	private function createService(array $tree, string $notesPath = 'Notes', string $notesAppPath = ''): NoteFolderCandidateService {
		$userFolder = $this->createFolder(self::USER_ROOT, $tree);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getNotesPath')->willReturn($notesPath);
		$settings->method('getNotesAppNotesPath')->willReturn($notesAppPath);
		$settings->method('getFileSuffix')->willReturn('.md');
		$settings->method('getIgnoreNoteSubFolders')->willReturn('^\.');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$folders = new NoteFolderService($rootFolder, $settings, $this->createMock(ILockingProvider::class));

		return new NoteFolderCandidateService($folders, $settings, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @param array<string, mixed> $tree
	 */
	private function createFolder(string $path, array $tree): Folder {
		/** @var array<string, Node> $children */
		$children = [];
		foreach ($tree as $name => $subTree) {
			$name = (string)$name;
			$childPath = $path . '/' . $name;
			if (is_array($subTree)) {
				$children[$name] = $this->createFolder($childPath, $subTree);
			} else {
				$file = $this->createMock(File::class);
				$file->method('getName')->willReturn($name);
				$file->method('getPath')->willReturn($childPath);
				$children[$name] = $file;
			}
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn($path);
		$folder->method('getDirectoryListing')->willReturn(array_values($children));
		$get = static function (string $relativePath) use ($children): Node {
			$segments = explode('/', trim($relativePath, '/'));
			$node = $children[array_shift($segments)] ?? null;
			if ($node === null) {
				throw new NotFoundException($relativePath);
			}
			return $segments === [] ? $node : $node->get(implode('/', $segments));
		};
		$folder->method('get')->willReturnCallback($get);
		$folder->method('nodeExists')->willReturnCallback(static function (string $relativePath) use ($get): bool {
			try {
				$get($relativePath);
				return true;
			} catch (NotFoundException) {
				return false;
			}
		});
		$folder->method('search')->willReturnCallback(fn (string $term): array => $this->search($folder, $term));
		$folder->method('getRelativePath')->willReturnCallback(static function (string $absolutePath) use ($path): ?string {
			if ($absolutePath === $path) {
				return '/';
			}
			return str_starts_with($absolutePath, $path . '/') ? substr($absolutePath, strlen($path)) : null;
		});

		return $folder;
	}

	/**
	 * @return list<Node>
	 */
	private function search(Folder $folder, string $term): array {
		$results = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (str_contains($node->getName(), $term)) {
				$results[] = $node;
			}
			if ($node instanceof Folder) {
				array_push($results, ...$this->search($node, $term));
			}
		}
		return $results;
	}

	public function testFindsLocalizedNotesAppFolderAndTagDatabaseFolders(): void {
		$service = $this->createService([
			'Notes' => ['Desktop.md' => null, 'notes.sqlite' => null],
			'Notizen' => ['A.md' => null, 'B.txt' => null, 'Work' => ['C.md' => null], 'image.png' => null],
			'Documents' => [
				'Desktop notes' => ['notes.sqlite' => null, 'notes.sqlite-journal' => null, 'D.md' => null],
				'Notes' => ['E.md' => null],
			],
			'Notas' => ['F.org' => null, 'G.markdown' => null, 'H.note' => null],
			'Notities' => ['photo.jpg' => null],
			'Notlar' => ['.hidden.md' => null, '.git' => ['I.md' => null], 'media' => ['J.md' => null]],
			'Photos' => ['K.md' => null],
			'readme.md' => null,
		], 'Notes', 'Notizen');

		$this->assertSame([
			[
				'path' => 'Notizen',
				'noteCount' => 3,
				'noteCountCapped' => false,
				'hasTagDatabase' => false,
				'sources' => ['notesApp', 'defaultName'],
			],
			[
				'path' => 'Documents/Desktop notes',
				'noteCount' => 1,
				'noteCountCapped' => false,
				'hasTagDatabase' => true,
				'sources' => ['tagDatabase'],
			],
			[
				'path' => 'Notas',
				'noteCount' => 3,
				'noteCountCapped' => false,
				'hasTagDatabase' => false,
				'sources' => ['defaultName'],
			],
		], $service->getCandidates('alice'));
	}

	public function testExcludesNoteFolderItsSubfoldersAndAncestors(): void {
		$service = $this->createService([
			'Documents' => [
				'notes.sqlite' => null,
				'Notes' => [
					'A.md' => null,
					'notes.sqlite' => null,
					'Archive' => ['notes.sqlite' => null, 'B.md' => null],
				],
			],
			'Notes' => ['C.md' => null],
			'notes.sqlite' => null,
		], 'Documents/Notes', 'Documents/Notes/Archive');

		$this->assertSame([
			[
				'path' => 'Notes',
				'noteCount' => 1,
				'noteCountCapped' => false,
				'hasTagDatabase' => false,
				'sources' => ['defaultName'],
			],
		], $service->getCandidates('alice'));
	}

	public function testFolderWithOnlyTagDatabaseIsCandidate(): void {
		$service = $this->createService(['Notes' => [], 'Notizen' => ['notes.sqlite' => null]]);

		$this->assertSame([
			[
				'path' => 'Notizen',
				'noteCount' => 0,
				'noteCountCapped' => false,
				'hasTagDatabase' => true,
				'sources' => ['defaultName', 'tagDatabase'],
			],
		], $service->getCandidates('alice'));
	}

	public function testMissingNotesAppFolderIsIgnored(): void {
		$service = $this->createService(['Notes' => ['A.md' => null]], 'Notes', 'Notizen');

		$this->assertSame([], $service->getCandidates('alice'));
	}

	public function testNoteCountIsCapped(): void {
		$files = [];
		for ($i = 0; $i < NoteFolderCandidateService::MAX_NOTE_COUNT + 5; $i++) {
			$files["Note $i.md"] = null;
		}
		$service = $this->createService(['Notes' => [], 'Notizen' => $files]);

		$candidates = $service->getCandidates('alice');
		$this->assertCount(1, $candidates);
		$this->assertSame(NoteFolderCandidateService::MAX_NOTE_COUNT, $candidates[0]['noteCount']);
		$this->assertTrue($candidates[0]['noteCountCapped']);
	}

	public function testResultsAreLimited(): void {
		$tree = ['Notes' => []];
		for ($i = 0; $i < NoteFolderCandidateService::MAX_CANDIDATES + 3; $i++) {
			$tree["Folder $i"] = ['notes.sqlite' => null];
		}

		$this->assertCount(NoteFolderCandidateService::MAX_CANDIDATES, $this->createService($tree)->getCandidates('alice'));
	}

	public static function folderNames(): array {
		return [
			['Notes', true],
			['notes', true],
			['NOTIZEN', true],
			['Заметки', true],
			['заметки', true],
			['ΣΗΜΕΙΏΣΕΙΣ', true],
			['笔记', true],
			["یادداشت\u{200C}ها", true],
			["កំណត់\u{200B}ចំណាំ", true],
			// Decomposed "á" of macOS file names
			["No\u{0301}tai\u{0301}", class_exists(\Normalizer::class)],
			['Notes 2', false],
			['My Notes', false],
			['', false],
		];
	}

	#[DataProvider('folderNames')]
	public function testIsDefaultFolderName(string $name, bool $expected): void {
		$this->assertSame($expected, NoteFolderCandidateService::isDefaultFolderName($name));
	}

	public static function overlappingPaths(): array {
		return [
			['Notes', 'Notes', true],
			['Notes/Work', 'Notes', true],
			['Documents', 'Documents/Notes', true],
			['Notes', 'Notes 2', false],
			['Notes 2', 'Notes', false],
			['Notizen', 'Notes', false],
		];
	}

	#[DataProvider('overlappingPaths')]
	public function testOverlaps(string $path, string $otherPath, bool $expected): void {
		$this->assertSame($expected, NoteFolderCandidateService::overlaps($path, $otherPath));
	}
}
