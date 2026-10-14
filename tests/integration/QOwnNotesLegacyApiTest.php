<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * QOwnNotes API 1.0, compatible with the qownnotesapi app for QOwnNotes Desktop and Android, see docs/api/qownnotes-api.md
 */
class QOwnNotesLegacyApiTest extends ApiTestCase {
	/**
	 * @param array<string, mixed> $params
	 */
	private static function legacy(string $endpoint, array $params = [], string $method = 'GET'): array {
		// Like qownnotesapi, errors are answered with status 200
		[$data] = self::api($method, "note/$endpoint?" . http_build_query($params));
		return $data;
	}

	public function testAppInfo(): void {
		$info = self::legacy('app_info', ['notes_path' => '/Missing']);
		$this->assertSame(self::$user, $info['user']);
		$this->assertTrue($info['versioning']);
		$this->assertIsBool($info['versions_app']);
		$this->assertIsBool($info['trash_app']);
		// QOwnNotes Desktop requires at least 0.4.2, Android 0.4.4
		$this->assertTrue(version_compare($info['app_version'], '0.4.4', '>='), $info['app_version']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $info['server_version']);
		$this->assertFalse($info['notes_path_exists']);

		self::davMkcol('Existing');
		$this->assertTrue(self::legacy('app_info', ['notes_path' => '/Existing/'])['notes_path_exists']);
		// Paths can't leave the user's files
		$this->assertTrue(self::legacy('app_info', ['notes_path' => '../../Existing'])['notes_path_exists']);
	}

	public function testVersions(): void {
		if (!self::legacy('app_info')['versions_app']) {
			$this->markTestSkipped('The versions app is disabled');
		}

		self::davMkcol('Legacy');
		self::davPut('Legacy/versioned.md', "First version of the note\n");
		self::nextSecond();
		self::davPut('Legacy/versioned.md', "Current version of the note\n");

		$versions = self::legacy('versions', ['file_name' => '/Legacy/versioned.md']);
		$this->assertSame('/Legacy/versioned.md', $versions['file_name']);
		$this->assertSame([], $versions['error_messages']);
		$this->assertNotEmpty($versions['versions']);
		$this->assertNotContains("Current version of the note\n", array_column($versions['versions'], 'data'), 'The current version is not listed');
		$this->assertContains("First version of the note\n", array_column($versions['versions'], 'data'));
		foreach ($versions['versions'] as $version) {
			$this->assertGreaterThan(0, $version['timestamp']);
			$this->assertNotEmpty($version['humanReadableTimestamp']);
		}
		$this->assertStringContainsString('<del>Current</del><ins>First</ins>', $versions['versions'][0]['diffHtml']);

		$missing = self::legacy('versions', ['file_name' => '/Legacy/missing.md']);
		$this->assertSame([], $missing['versions']);
		$this->assertSame(['Requested file was not found!'], $missing['error_messages']);
	}

	public function testDiffHtmlIsEscaped(): void {
		if (!self::legacy('app_info')['versions_app']) {
			$this->markTestSkipped('The versions app is disabled');
		}

		self::davMkcol('Escaped');
		self::davPut('Escaped/xss.md', "<script>alert(1)</script>\n");
		self::nextSecond();
		self::davPut('Escaped/xss.md', "<b>safe</b>\n");

		$diffHtml = self::legacy('versions', ['file_name' => 'Escaped/xss.md'])['versions'][0]['diffHtml'];
		$this->assertStringNotContainsString('<script>', $diffHtml);
		$this->assertStringNotContainsString('<b>', $diffHtml);
		$this->assertStringContainsString('&lt;', $diffHtml);
	}

	public function testTrashAndRestore(): void {
		if (!self::legacy('app_info')['trash_app']) {
			$this->markTestSkipped('The deleted files app is disabled');
		}

		self::davMkcol('Trash');
		self::davMkcol('Trash/Sub');
		self::davMkcol('TrashOther');
		$files = [
			'Trash/trashed.md' => "Trashed note\n",
			'Trash/custom.qnote' => "Custom extension\n",
			'Trash/ignored.json' => "{}\n",
			'Trash/Sub/nested.md' => "Nested note\n",
			'TrashOther/outside.md' => "Outside\n",
		];
		foreach ($files as $path => $content) {
			self::davPut($path, $content);
			$this->assertSame(204, self::dav('DELETE', $path)->getStatusCode());
		}

		$trash = self::legacy('trashed', ['dir' => '/Trash/', 'extensions' => ['qnote']]);
		$this->assertSame('Trash', $trash['directory']);
		$byName = array_column($trash['notes'], null, 'fileName');
		$this->assertEqualsCanonicalizing(['trashed.md', 'custom.qnote'], array_keys($byName));
		$this->assertSame('trashed', $byName['trashed.md']['noteName']);
		$this->assertSame("Trashed note\n", $byName['trashed.md']['data']);
		$this->assertSame('Trash/trashed.md', $byName['trashed.md']['originalLocation']);
		$this->assertNotEmpty($byName['trashed.md']['dateString']);

		// content=0 skips reading the notes
		$withoutContent = self::legacy('trashed', ['dir' => 'Trash', 'extensions' => ['qnote'], 'content' => '0']);
		$this->assertEqualsCanonicalizing(['trashed.md', 'custom.qnote'], array_column($withoutContent['notes'], 'fileName'));
		$this->assertSame(['', ''], array_column($withoutContent['notes'], 'data'));

		$recursive = self::legacy('trashed', ['dir' => 'Trash', 'recursive' => '1']);
		$this->assertEqualsCanonicalizing(['trashed.md', 'nested.md'], array_column($recursive['notes'], 'fileName'));

		// Sorting by name, ascending
		$sorted = self::legacy('trashed', ['dir' => 'Trash', 'extensions' => ['qnote'], 'sort' => 'name', 'sortdirection' => 'asc']);
		$this->assertSame(['custom.qnote', 'trashed.md'], array_column($sorted['notes'], 'fileName'));

		$timestamp = $byName['trashed.md']['timestamp'];
		$restore = self::legacy('restore_trashed', ['file_name' => '/Trash/trashed.md', 'timestamp' => $timestamp]);
		$this->assertSame(['result' => true, 'path' => "//trashed.md.d$timestamp", 'filename' => 'trashed.md'], $restore);
		$this->assertSame("Trashed note\n", self::davGet('Trash/trashed.md'));

		$nested = array_column($recursive['notes'], null, 'fileName')['nested.md'];
		$restore = self::legacy('restore_trashed', ['file_name' => 'Trash/Sub/nested.md', 'timestamp' => $nested['timestamp']], 'POST');
		$this->assertTrue($restore['result']);
		$this->assertSame("Nested note\n", self::davGet('Trash/Sub/nested.md'));

		$this->assertFalse(self::legacy('restore_trashed', ['file_name' => '/Trash/unknown.md', 'timestamp' => 1])['result']);
	}
}
