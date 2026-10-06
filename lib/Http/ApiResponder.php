<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Http;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Exception\InsufficientStorageException;
use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Exception\NotWritableException;
use OCA\QOwnNotes\Exception\PreconditionFailedException;
use OCA\QOwnNotes\Exception\QOwnNotesException;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs API actions and turns their results and exceptions into HTTP responses
 */
class ApiResponder {
	private const LOCK_RETRIES = 3;

	public function __construct(
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No user is logged in');
		}

		return $user->getUID();
	}

	/**
	 * @param callable(): (Response|array|null) $action
	 */
	public function respond(callable $action): Response {
		try {
			$result = $this->retryIfLocked($action);
			$response = $result instanceof Response ? $result : new JSONResponse($result ?? []);
		} catch (Throwable $e) {
			$response = $this->createErrorResponse($e);
		}

		$response->addHeader('X-Notes-API-Versions', implode(', ', Application::NOTES_API_VERSIONS));
		$response->addHeader('X-QOwnNotes-API-Versions', implode(', ', Application::QOWNNOTES_API_VERSIONS));
		return $response;
	}

	/**
	 * Sets the ETag of a response and turns it into "304 Not Modified" if the client already has this state
	 */
	public static function withETag(Response $response, string $etag, IRequest $request): Response {
		$response->setETag($etag);
		if (self::etagMatches($request->getHeader('If-None-Match'), $etag)) {
			$response->setStatus(Http::STATUS_NOT_MODIFIED);
		}

		return $response;
	}

	/**
	 * Whether a list of entity tags from an If-Match or If-None-Match header contains the given ETag;
	 * quoted, unquoted and weak ETags are accepted
	 */
	public static function etagMatches(string $header, string $etag): bool {
		if (trim($header) === '') {
			return false;
		}

		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '*') {
				return true;
			}
			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}
			if (trim($candidate, '"') === $etag) {
				return true;
			}
		}

		return false;
	}

	private function createErrorResponse(Throwable $e): JSONResponse {
		$status = match (true) {
			$e instanceof PreconditionFailedException => Http::STATUS_PRECONDITION_FAILED,
			$e instanceof NoteNotFoundException, $e instanceof NotFoundException => Http::STATUS_NOT_FOUND,
			$e instanceof NotWritableException, $e instanceof NotPermittedException, $e instanceof ForbiddenException => Http::STATUS_FORBIDDEN,
			$e instanceof InvalidInputException, $e instanceof InvalidPathException => Http::STATUS_BAD_REQUEST,
			$e instanceof InsufficientStorageException => Http::STATUS_INSUFFICIENT_STORAGE,
			$e instanceof LockedException => Http::STATUS_LOCKED,
			$e instanceof ServiceUnavailableException => Http::STATUS_SERVICE_UNAVAILABLE,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};

		if ($e instanceof PreconditionFailedException) {
			return new JSONResponse($e->getCurrentData(), $status);
		}

		if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
			$this->logger->error('QOwnNotes API request failed', ['exception' => $e]);
		} else {
			$this->logger->debug('QOwnNotes API request failed', ['exception' => $e]);
		}

		return new JSONResponse([
			'errorType' => (new \ReflectionClass($e))->getShortName(),
			'message' => $e instanceof QOwnNotesException ? $e->getMessage() : '',
		], $status);
	}

	/**
	 * @template T
	 * @param callable(): T $action
	 * @return T
	 */
	private function retryIfLocked(callable $action): mixed {
		for ($try = 1; ; $try++) {
			try {
				return $action();
			} catch (LockedException $e) {
				if ($try >= self::LOCK_RETRIES) {
					throw $e;
				}
				usleep(200000 * $try);
			}
		}
	}
}
