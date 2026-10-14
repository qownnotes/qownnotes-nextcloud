<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

class CapabilitiesTest extends ApiTestCase {
	public function testOnlyTheQOwnNotesCapabilityIsPublished(): void {
		$capabilities = self::ocs('GET', 'cloud/capabilities')['capabilities'];

		$this->assertArrayHasKey('qownnotes', $capabilities);
		// The Nextcloud Notes app may publish "notes" itself; this app must not change it
		if (isset($capabilities['notes'])) {
			$this->assertArrayNotHasKey('qownnotes_api_version', $capabilities['notes']);
			$this->assertArrayNotHasKey('api_base', $capabilities['notes']);
		}

		$qownnotes = $capabilities['qownnotes'];
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $qownnotes['version']);
		$this->assertSame(['1.4'], $qownnotes['notes_api_version']);
		$this->assertSame(['1.1'], $qownnotes['qownnotes_api_version']);
		$this->assertSame('/index.php/apps/qownnotes/api/v1/', $qownnotes['api_base']);
		$this->assertIsBool($qownnotes['ui_enabled']);
		$this->assertSame('Notes', $qownnotes['notes_path']);
		$this->assertIsBool($qownnotes['versions_app']);
		$this->assertIsBool($qownnotes['trash_app']);
		$this->assertTrue($qownnotes['tags']['available'], 'The server needs pdo_sqlite');
		$this->assertSame([15, 16], $qownnotes['tags']['writable_schema_versions']);
	}

	public function testEveryApiResponseHasTheVersionHeaders(): void {
		foreach (['settings', 'notes', 'note/app_info', 'subfolders', 'tags'] as $path) {
			[, $response] = self::api('GET', $path);
			$this->assertSame('1.4', $response->getHeaderLine('X-Notes-API-Versions'), $path);
			$this->assertSame('1.1', $response->getHeaderLine('X-QOwnNotes-API-Versions'), $path);
		}
	}

	public function testRequestsWithoutAuthenticationAreRejected(): void {
		$response = self::$client->get(self::API_PATH . 'notes', ['headers' => ['Accept' => 'application/json']]);
		$this->assertSame(401, $response->getStatusCode());
	}

	public function testCorsPreflight(): void {
		$response = self::request('OPTIONS', self::API_PATH . 'notes', [
			'auth' => null,
			'headers' => ['Origin' => 'https://example.com', 'Access-Control-Request-Method' => 'PUT'],
		]);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('PUT', $response->getHeaderLine('Access-Control-Allow-Methods'));
	}
}
