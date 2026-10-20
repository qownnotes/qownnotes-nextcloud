<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCA\QOwnNotes\Service\LinkTitleService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class LinkTitleServiceTest extends TestCase {
	public static function invalidUrls(): array {
		return array_map(static fn (string $url): array => [$url], [
			'', 'file:///etc/passwd', 'ftp://example.com', '//example.com',
			'https://user:password@example.com', "https://example.com/\npath", 'http://127.0.0.1',
		]);
	}

	#[DataProvider('invalidUrls')]
	public function testRejectsInvalidUrlsBeforeRequest(string $url): void {
		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->never())->method('newClient');
		$validator = $this->createMock(IRemoteHostValidator::class);
		$validator->method('isValid')->willReturn(false);
		$this->expectException(InvalidInputException::class);
		(new LinkTitleService($clients, $validator))->fetch($url);
	}

	public function testExtractsPlainUnicodeTitleWithoutExecutingMarkup(): void {
		$this->assertSame('Über & café [notes]', LinkTitleService::extractTitle("<html><head><title> Über &amp; café\n [notes] </title></head></html>"));
		$this->assertSame('', LinkTitleService::extractTitle('<html><body>No title</body></html>'));
		$this->assertSame(1000, mb_strlen(LinkTitleService::extractTitle('<title>' . str_repeat('é', 1200) . '</title>')));
	}

	public function testFetchesBoundedHtmlAndClosesStream(): void {
		$body = fopen('php://memory', 'r+');
		$this->assertIsResource($body);
		fwrite($body, '<title>Example &amp; test</title>' . str_repeat('x', 300000));
		rewind($body);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->with('Content-Type')->willReturn('text/html; charset=utf-8');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->with('https://example.com', $this->callback(function (array $options): bool {
			$this->assertTrue($options['stream']);
			$this->assertSame(10, $options['timeout']);
			$this->assertSame(3, $options['allow_redirects']['max']);
			$this->assertArrayNotHasKey('cookies', $options);
			$this->assertArrayNotHasKey('Authorization', $options['headers']);
			return true;
		}))->willReturn($response);
		$this->assertSame('Example & test', $this->service($client)->fetch('https://example.com'));
		$this->assertFalse(is_resource($body));
	}

	public function testRejectsRedirectToLocalHost(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): never {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('__toString')->willReturn('http://127.0.0.1/private');
			$options['allow_redirects']['on_redirect']($this->createMock(RequestInterface::class), $this->createMock(ResponseInterface::class), $uri);
			$this->fail('The redirect must be rejected');
		});
		$this->expectException(InvalidInputException::class);
		$this->service($client)->fetch('https://example.com');
	}

	public function testRejectsNonHtmlAndClosesStream(): void {
		$body = fopen('php://memory', 'r+');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->willReturn('application/pdf');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		try {
			$this->service($client)->fetch('https://example.com');
			$this->fail('Non-HTML must be rejected');
		} catch (ServiceUnavailableException) {
			$this->assertFalse(is_resource($body));
		}
	}

	private function service(IClient $client): LinkTitleService {
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$validator = $this->createMock(IRemoteHostValidator::class);
		$validator->method('isValid')->willReturnCallback(static fn (string $host): bool => $host === 'example.com');
		return new LinkTitleService($clients, $validator);
	}
}
