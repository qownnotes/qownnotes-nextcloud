<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoteFolderServiceTest extends TestCase {
	private function createService(string $ignorePatterns = '^\.', string $suffix = '.md'): NoteFolderService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getIgnoreNoteSubFolders')->willReturn($ignorePatterns);
		$settings->method('getFileSuffix')->willReturn($suffix);

		return new NoteFolderService($this->createMock(IRootFolder::class), $settings);
	}

	public static function folderNames(): array {
		return [
			['Work', '^\.', false],
			['media', '^\.', true],
			['attachments', '^\.', true],
			['trash', '^\.', true],
			['.git', '^\.', true],
			['.hidden', '', false],
			['Archive', '^\.;^Arch', true],
			['My Archive', '^\.;^Arch', false],
			['a/b', 'a/b', true],
			['tmp', '^\.;;tmp$', true],
			// Invalid expressions are skipped
			['Work', '(', false],
		];
	}

	#[DataProvider('folderNames')]
	public function testIsIgnoredFolderName(string $name, string $patterns, bool $expected): void {
		$this->assertSame($expected, $this->createService($patterns)->isIgnoredFolderName('alice', $name));
	}

	public function testIsIgnoredSubFolderPath(): void {
		$service = $this->createService();
		$this->assertFalse($service->isIgnoredSubFolderPath('alice', ''));
		$this->assertFalse($service->isIgnoredSubFolderPath('alice', 'Work/Project'));
		$this->assertTrue($service->isIgnoredSubFolderPath('alice', 'Work/media'));
		$this->assertTrue($service->isIgnoredSubFolderPath('alice', '.hidden/Project'));
	}

	public static function fileNames(): array {
		return [
			['Note.md', '.md', true],
			['Note.MD', '.md', true],
			['Note.txt', '.md', true],
			['Note.org', '.md', false],
			['Note.org', '.org', true],
			['.hidden.md', '.md', false],
			['notes.sqlite', '.md', false],
			['image.png', '.md', false],
			['', '.md', false],
		];
	}

	#[DataProvider('fileNames')]
	public function testIsNoteFileName(string $fileName, string $suffix, bool $expected): void {
		$this->assertSame($expected, $this->createService('^\.', $suffix)->isNoteFileName('alice', $fileName));
	}
}
