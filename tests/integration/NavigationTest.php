<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * The navigation entry and the web interface, which users can hide from their navigation
 */
class NavigationTest extends ApiTestCase {
	/**
	 * @return list<string>
	 */
	private static function navigationAppIds(): array {
		return array_column(self::ocs('GET', 'core/navigation/apps'), 'id');
	}

	public function testUsersCanHideTheNavigationEntry(): void {
		if (!self::ocs('GET', 'cloud/capabilities')['capabilities']['qownnotes']['ui_enabled']) {
			$this->markTestSkipped('The web interface is disabled (API-only mode)');
		}

		$this->assertContains('qownnotes', self::navigationAppIds());

		[$settings] = self::api('PUT', 'settings', ['hideNavigation' => true]);
		$this->assertTrue($settings['hideNavigation']);
		$this->assertNotContains('qownnotes', self::navigationAppIds());
		// The web interface stays available
		$this->assertSame(200, self::request('GET', '/index.php/apps/qownnotes/', ['headers' => ['Accept' => 'text/html']])->getStatusCode());
		// Other users still see the entry
		$admin = self::request('GET', '/ocs/v2.php/core/navigation/apps', ['auth' => self::adminAuth()]);
		$this->assertContains('qownnotes', array_column(json_decode((string)$admin->getBody(), true)['ocs']['data'], 'id'));

		[$settings] = self::api('PUT', 'settings', ['hideNavigation' => false]);
		$this->assertFalse($settings['hideNavigation']);
		$this->assertContains('qownnotes', self::navigationAppIds());
	}
}
