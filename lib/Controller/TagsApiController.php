<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\TagService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Tags of the note folder database notes.sqlite (QOwnNotes API 1.1); all responses contain the ETag of
 * notes.sqlite, which has to be sent as If-Match header with changes to prevent lost updates
 */
class TagsApiController extends ApiController {
	public function __construct(
		IRequest $request,
		private ApiResponder $responder,
		private TagService $tagService,
		private NoteService $noteService,
	) {
		parent::__construct(Application::APP_ID, $request, 'PUT, POST, GET, DELETE, PATCH', 'Authorization, Content-Type, Accept, If-Match, If-None-Match');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function index(): Response {
		return $this->respond(fn (): array => $this->tagService->getTagTree($this->responder->getUserId()), true);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function create(): Response {
		return $this->respond(fn (): array => $this->tagService->createTag($this->responder->getUserId(), $this->request->getParams(), $this->getIfMatch()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function update(int $id): Response {
		return $this->respond(fn (): array => $this->tagService->updateTag($this->responder->getUserId(), $id, $this->request->getParams(), $this->getIfMatch()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function destroy(int $id): Response {
		return $this->respond(fn (): array => $this->tagService->deleteTag($this->responder->getUserId(), $id, $this->getIfMatch()));
	}

	/**
	 * @param list<array<string, mixed>> $operations
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function batch(mixed $operations = []): Response {
		return $this->respond(function () use ($operations): array {
			if (!is_array($operations)) {
				throw new InvalidInputException('operations must be a list');
			}
			return $this->tagService->batch($this->responder->getUserId(), array_values($operations), $this->getIfMatch());
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function links(): Response {
		return $this->respond(fn (): array => $this->tagService->getTagLinks($this->responder->getUserId()), true);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getNoteTags(int $id): Response {
		return $this->respond(function () use ($id): array {
			$userId = $this->responder->getUserId();
			return $this->tagService->getNoteTags($userId, $this->noteService->get($userId, $id));
		}, true);
	}

	/**
	 * @param list<int>|null $tagIds
	 * @param list<list<string>|string>|null $tagPaths
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function setNoteTags(int $id, mixed $tagIds = null, mixed $tagPaths = null): Response {
		return $this->respond(function () use ($id, $tagIds, $tagPaths): array {
			if (($tagIds !== null && !is_array($tagIds)) || ($tagPaths !== null && !is_array($tagPaths)) || ($tagIds === null && $tagPaths === null)) {
				throw new InvalidInputException('tagIds or tagPaths must be a list');
			}

			$userId = $this->responder->getUserId();
			$note = $this->noteService->get($userId, $id);
			$paths = $tagPaths === null ? null : array_map(static fn (mixed $path): array => is_array($path) ? array_values($path) : explode('/', (string)$path), array_values($tagPaths));
			return $this->tagService->setNoteTags($userId, $note, $tagIds === null ? null : array_map('intval', array_values($tagIds)), $paths, $this->getIfMatch());
		});
	}

	/**
	 * @param callable(): array $action
	 */
	private function respond(callable $action, bool $cacheable = false): Response {
		return $this->responder->respond(function () use ($action, $cacheable): Response {
			$data = $action();
			$response = new JSONResponse($data);
			if (is_string($data['etag'] ?? null)) {
				if ($cacheable) {
					// The data also depends on the notes, so the ETag of the response combines both
					return ApiResponder::withETag($response, md5(json_encode($data, JSON_THROW_ON_ERROR)), $this->request);
				}
				$response->setETag($data['etag']);
			}
			return $response;
		});
	}

	private function getIfMatch(): ?string {
		$ifMatch = $this->request->getHeader('If-Match');
		return $ifMatch === '' ? null : $ifMatch;
	}
}
