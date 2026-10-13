<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\AppSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppSettingsTest extends TestCase {
	public static function uiValues(): array {
		return [
			['yes', true],
			['1', true],
			['true', true],
			['', true],
			['no', false],
			['NO', false],
			['0', false],
			['false', false],
			['off', false],
		];
	}

	#[DataProvider('uiValues')]
	public function testIsUiEnabled(string $value, bool $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('qownnotes', 'ui_enabled', 'yes')->willReturn($value);

		$this->assertSame($expected, (new AppSettings($appConfig))->isUiEnabled());
	}

	public function testSetUiEnabled(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('setValueString')->with('qownnotes', 'ui_enabled', 'no');

		(new AppSettings($appConfig))->setUiEnabled(false);
	}

	public static function maxSizeValues(): array {
		return [
			['50', 50],
			[' 10 ', 10],
			['1', 1],
			['1024', 1024],
			['0', 50],
			['1025', 50],
			['-5', 50],
			['1.5', 50],
			['', 50],
			['big', 50],
		];
	}

	#[DataProvider('maxSizeValues')]
	public function testGetTagDatabaseMaxSizeMib(string $value, int $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('qownnotes', 'tag_database_max_size_mib', '50')->willReturn($value);

		$this->assertSame($expected, (new AppSettings($appConfig))->getTagDatabaseMaxSizeMib());
	}

	public function testSetTagDatabaseMaxSizeMibIsClamped(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(2))->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				static $expected = ['1', '1024'];
				$this->assertSame('tag_database_max_size_mib', $key);
				$this->assertSame(array_shift($expected), $value);
				return true;
			});

		$settings = new AppSettings($appConfig);
		$settings->setTagDatabaseMaxSizeMib(0);
		$settings->setTagDatabaseMaxSizeMib(5000);
	}
}
