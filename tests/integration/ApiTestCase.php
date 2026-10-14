<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class of the API integration tests against a running Nextcloud server with the app enabled (by default the
 * docker dev server, see docker/README.md). Every test class works with its own user, which is created by the
 * admin before the first test and deleted after the last one.
 *
 * Environment variables: NEXTCLOUD_URL (default http://localhost:8081), NEXTCLOUD_ADMIN_USER and
 * NEXTCLOUD_ADMIN_PASSWORD (default admin/admin).
 */
abstract class ApiTestCase extends TestCase {
	protected const API_PATH = '/index.php/apps/qownnotes/api/v1/';

	protected static Client $client;
	protected static string $user;
	protected static string $password;

	public static function setUpBeforeClass(): void {
		self::$client = new Client([
			'base_uri' => rtrim(getenv('NEXTCLOUD_URL') ?: 'http://localhost:8081', '/'),
			'http_errors' => false,
			'timeout' => 60,
		]);

		try {
			$status = self::$client->get('/status.php');
		} catch (GuzzleException $e) {
			self::fail('The Nextcloud server is not reachable, start it with "cd docker && just up": ' . $e->getMessage());
		}
		$status = json_decode((string)$status->getBody(), true);
		self::assertTrue($status['installed'] ?? false, 'Nextcloud is not installed yet');

		$name = strtolower((new \ReflectionClass(static::class))->getShortName());
		self::$user = 'qn-' . substr($name, 0, 20) . '-' . bin2hex(random_bytes(3));
		self::$password = 'Qn-Integration-' . bin2hex(random_bytes(12));
		$response = self::$client->post('/ocs/v2.php/cloud/users', [
			'auth' => self::adminAuth(),
			'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
			'form_params' => ['userid' => self::$user, 'password' => self::$password],
		]);
		self::assertSame(200, $response->getStatusCode(), 'The test user could not be created: ' . $response->getBody());
	}

	public static function tearDownAfterClass(): void {
		self::$client->delete('/ocs/v2.php/cloud/users/' . rawurlencode(self::$user), [
			'auth' => self::adminAuth(),
			'headers' => ['OCS-APIRequest' => 'true'],
		]);
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	protected static function adminAuth(): array {
		return [getenv('NEXTCLOUD_ADMIN_USER') ?: 'admin', getenv('NEXTCLOUD_ADMIN_PASSWORD') ?: 'admin'];
	}

	/**
	 * @param array<string, mixed> $options Guzzle request options
	 */
	protected static function request(string $method, string $uri, array $options = []): ResponseInterface {
		$options['auth'] ??= [self::$user, self::$password];
		$options['headers'] = array_merge(['Accept' => 'application/json', 'OCS-APIRequest' => 'true'], $options['headers'] ?? []);
		return self::$client->request($method, $uri, $options);
	}

	/**
	 * Calls the app API and checks the status code
	 *
	 * @param mixed $json request body, sent as JSON unless null
	 * @param array<string, string> $headers
	 * @return array{0: mixed, 1: ResponseInterface} the decoded JSON body and the response
	 */
	protected static function api(string $method, string $path, mixed $json = null, array $headers = [], int $expect = 200): array {
		$options = ['headers' => $headers];
		if ($json !== null) {
			$options['json'] = $json;
		}
		$response = self::request($method, self::API_PATH . $path, $options);
		$body = (string)$response->getBody();
		self::assertSame($expect, $response->getStatusCode(), "$method $path: $body");

		return [$body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR), $response];
	}

	/**
	 * @return mixed the data of an OCS response
	 */
	protected static function ocs(string $method, string $path, array $options = []): mixed {
		$response = self::request($method, '/ocs/v2.php/' . ltrim($path, '/'), $options);
		self::assertSame(200, $response->getStatusCode(), "OCS $method $path: " . $response->getBody());
		return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['ocs']['data'];
	}

	/**
	 * A WebDAV request in the files of the test user
	 */
	protected static function dav(string $method, string $path, ?string $body = null, array $headers = []): ResponseInterface {
		$segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));
		$options = ['headers' => $headers];
		if ($body !== null) {
			$options['body'] = $body;
		}

		return self::request($method, '/remote.php/dav/files/' . rawurlencode(self::$user) . '/' . implode('/', $segments), $options);
	}

	protected static function davPut(string $path, string $content): void {
		self::assertContains(self::dav('PUT', $path, $content)->getStatusCode(), [201, 204], "WebDAV PUT $path");
	}

	protected static function davMkcol(string $path): void {
		self::assertSame(201, self::dav('MKCOL', $path)->getStatusCode(), "WebDAV MKCOL $path");
	}

	protected static function davExists(string $path): bool {
		return self::dav('PROPFIND', $path, null, ['Depth' => '0'])->getStatusCode() === 207;
	}

	protected static function davGet(string $path): string {
		$response = self::dav('GET', $path);
		self::assertSame(200, $response->getStatusCode(), "WebDAV GET $path");
		return (string)$response->getBody();
	}

	/**
	 * Waits until the next second, so the next change of a file gets another mtime and a new version
	 */
	protected static function nextSecond(): void {
		usleep((int)((1.0 - fmod(microtime(true), 1.0)) * 1_000_000) + 100_000);
	}
}
