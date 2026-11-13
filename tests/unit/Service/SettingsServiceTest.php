<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase {
	public static function paths(): array {
		return [
			['Notes', 'Notes'],
			['/Notes/', 'Notes'],
			['Documents\\Notes', 'Documents/Notes'],
			['a/./b//c', 'a/b/c'],
			['../../etc/passwd', 'etc/passwd'],
			['a/../../b', 'b'],
			['', ''],
			['/', ''],
		];
	}

	#[DataProvider('paths')]
	public function testNormalizePath(string $path, string $expected): void {
		$this->assertSame($expected, SettingsService::normalizePath($path));
	}

	public static function suffixes(): array {
		return [
			['.md', '.md'],
			['md', '.md'],
			['.TXT', '.txt'],
			['.org', '.org'],
			['custom', '.md'],
			['', '.md'],
			['../x', '.x'],
		];
	}

	#[DataProvider('suffixes')]
	public function testNormalizeFileSuffix(string $suffix, string $expected): void {
		$this->assertSame($expected, SettingsService::normalizeFileSuffix($suffix));
	}

	public function testNotesPathIsTakenOverFromNotesApp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'qownnotes', 'notesPath', '', ''],
			['alice', 'notes', 'notesPath', '', '/Documents/Notes/'],
		]);
		$config->expects($this->once())->method('setUserValue')->with('alice', 'qownnotes', 'notesPath', 'Documents/Notes');

		$this->assertSame('Documents/Notes', (new SettingsService($config))->getNotesPath('alice'));
	}

	public function testNotesPathIsTakenOverFromNotesAppSettingsObject(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'qownnotes', 'notesPath', '', ''],
			['alice', 'notes', 'settings', '', '{"notesPath":"Notizen\/","fileSuffix":".md"}'],
			['alice', 'notes', 'notesPath', '', 'Legacy'],
		]);
		$config->expects($this->once())->method('setUserValue')->with('alice', 'qownnotes', 'notesPath', 'Notizen');

		$this->assertSame('Notizen', (new SettingsService($config))->getNotesPath('alice'));
	}

	public static function invalidNotesAppSettings(): array {
		return [
			['not json'],
			['"Notizen"'],
			['{"notesPath":42}'],
			['{"notesPath":["Notizen"]}'],
			['{"notesPath":"/"}'],
			['{"fileSuffix":".txt"}'],
			[''],
		];
	}

	#[DataProvider('invalidNotesAppSettings')]
	public function testInvalidNotesAppSettingsFallBackToLegacyNotesPath(string $settings): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'notes', 'settings', '', $settings],
			['alice', 'notes', 'notesPath', '', 'Legacy Notes'],
		]);

		$this->assertSame('Legacy Notes', (new SettingsService($config))->getNotesAppNotesPath('alice'));
	}

	public function testNotesPathFallsBackToDefault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'qownnotes', 'notesPath', '', ''],
			['alice', 'notes', 'settings', '', '{"notesPath":""}'],
			['alice', 'notes', 'notesPath', '', ''],
		]);
		$config->expects($this->once())->method('setUserValue')->with('alice', 'qownnotes', 'notesPath', 'Notes');

		$this->assertSame('Notes', (new SettingsService($config))->getNotesPath('alice'));
	}

	public function testStoredNotesPathIsNotReplacedByNotesAppFolder(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'qownnotes', 'notesPath', '', 'Notes'],
			['alice', 'notes', 'settings', '', '{"notesPath":"Notizen"}'],
		]);
		$config->expects($this->never())->method('setUserValue');

		$this->assertSame('Notes', (new SettingsService($config))->getNotesPath('alice'));
	}

	public function testSetIgnoresUnknownKeysAndValidates(): void {
		$config = $this->createMock(IConfig::class);
		$stored = [];
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, string $value) use (&$stored): void {
			$stored[$key] = $value;
		});

		(new SettingsService($config))->set('alice', [
			'notesPath' => '../Private/Notes',
			'fileSuffix' => 'txt',
			'subfoldersEnabled' => false,
			'hideNavigation' => 'true',
			'unknown' => 'value',
		]);

		$this->assertSame([
			'notesPath' => 'Private/Notes',
			'fileSuffix' => '.txt',
			'subfoldersEnabled' => 'no',
			'hideNavigation' => 'yes',
		], $stored);
	}
}
