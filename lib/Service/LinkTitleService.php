<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCP\Http\Client\IClientService;
use OCP\Security\IRemoteHostValidator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class LinkTitleService {
	private const MAX_BYTES = 262144;

	public function __construct(
		private IClientService $clientService,
		private IRemoteHostValidator $hostValidator,
	) {
	}

	public function fetch(string $url): string {
		$url = trim($url);
		$this->validateUrl($url);
		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => 10,
				'connect_timeout' => 5,
				'stream' => true,
				'headers' => ['Accept' => 'text/html, application/xhtml+xml'],
				'allow_redirects' => [
					'max' => 3,
					'protocols' => ['http', 'https'],
					// Supplying allow_redirects replaces the client's default redirect guard.
					'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
						$this->validateUrl((string)$uri);
					},
				],
			]);
			$body = $response->getBody();
			try {
				$contentType = strtolower($response->getHeader('Content-Type'));
				if ($response->getStatusCode() !== 200 || (!str_starts_with($contentType, 'text/html') && !str_starts_with($contentType, 'application/xhtml+xml'))) {
					throw new ServiceUnavailableException('The URL did not return an HTML page');
				}
				$html = is_resource($body) ? stream_get_contents($body, self::MAX_BYTES) : substr($body, 0, self::MAX_BYTES);
				return self::extractTitle($html === false ? '' : $html);
			} finally {
				if (is_resource($body)) {
					fclose($body);
				}
			}
		} catch (InvalidInputException|ServiceUnavailableException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ServiceUnavailableException('The page title could not be fetched', $e);
		}
	}

	private function validateUrl(string $url): void {
		$parts = parse_url($url);
		if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)
			|| $parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			|| empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
			|| !$this->hostValidator->isValid($parts['host'])) {
			throw new InvalidInputException('Only public HTTP and HTTPS URLs without credentials can be fetched');
		}
	}

	public static function extractTitle(string $html): string {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			$title = $document->getElementsByTagName('title')->item(0)?->textContent ?? '';
			return mb_substr(trim(preg_replace('/\s+/u', ' ', $title) ?? ''), 0, 1000);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}
}
