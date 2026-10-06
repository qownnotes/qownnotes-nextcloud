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
}
