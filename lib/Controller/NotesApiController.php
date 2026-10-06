<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Db\Meta;
use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\PreconditionFailedException;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Http\ChunkCursor;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Service\AttachmentService;
use OCA\QOwnNotes\Service\MetaService;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\NoteTitle;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;

/**
 * Nextcloud Notes API v1 (up to v1.4) for QOwnNotes Android, see https://github.com/nextcloud/notes/blob/main/docs/api/v1.md
 */
class NotesApiController extends ApiController {
	/** Request header that lets clients opt in to server-side relinking of tags in notes.sqlite on renames/moves */
	public const RELINK_TAGS_HEADER = 'X-QOwnNotes-Relink-Tags';

	private const NOTE_ATTRIBUTES = ['id', 'etag', 'readonly', 'content', 'title', 'category', 'favorite', 'modified'];

	public function __construct(
		IRequest $request,
		private ApiResponder $responder,
		private NoteService $noteService,
		private MetaService $metaService,
		private SettingsService $settingsService,
		private NoteFolderService $folderService,
		private AttachmentService $attachmentService,
		private IMimeTypeDetector $mimeTypeDetector,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct(Application::APP_ID, $request, 'PUT, POST, GET, DELETE, PATCH', 'Authorization, Content-Type, Accept, If-Match, If-None-Match, ' . self::RELINK_TAGS_HEADER);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function index(?string $category = null, string $exclude = '', int $pruneBefore = 0, int $chunkSize = 0, ?string $chunkCursor = null): Response {
		return $this->responder->respond(function () use ($category, $exclude, $pruneBefore, $chunkSize, $chunkCursor): Response {
			$userId = $this->responder->getUserId();
			$cursor = null;
			if ($chunkCursor !== null && $chunkCursor !== '') {
				$cursor = ChunkCursor::fromString($chunkCursor);
				if ($cursor === null) {
					throw new InvalidInputException('The chunk cursor is invalid');
				}
			}
			$timeStart = $cursor !== null ? $cursor->timeStart : $this->timeFactory->getTime();

			$notes = $this->noteService->getAll($userId);
			if ($category !== null) {
				$notes = array_filter($notes, static fn (Note $note): bool => $note->getSubFolderPath() === $category);
			}
			$metas = $this->metaService->getAll($userId, $notes);

			// Notes that are sent completely; all others only with their ID
			$fullNoteIds = array_filter(array_keys($notes), static function (int $id) use ($metas, $pruneBefore, $cursor): bool {
				$lastUpdate = $metas[$id]->getLastUpdate();
				$isPruned = $pruneBefore > 0 && $lastUpdate < $pruneBefore;
				return !$isPruned && ($cursor === null || !$cursor->isBefore($lastUpdate, $id));
			});
			usort($fullNoteIds, static fn (int $a, int $b): int => [$metas[$a]->getLastUpdate(), $a] <=> [$metas[$b]->getLastUpdate(), $b]);

			$chunkIds = $chunkSize > 0 ? array_slice($fullNoteIds, 0, $chunkSize) : $fullNoteIds;
			$pendingCount = count($fullNoteIds) - count($chunkIds);
			$excluded = array_filter(explode(',', $exclude));

			$data = [];
			foreach ($chunkIds as $id) {
				$data[$id] = $this->serializeNote($notes[$id], $metas[$id], $excluded);
			}

			$response = new JSONResponse([]);
			if ($pendingCount > 0) {
				$lastId = $chunkIds[array_key_last($chunkIds)];
				$nextCursor = new ChunkCursor($timeStart, $metas[$lastId]->getLastUpdate(), $lastId);
				$response->addHeader('X-Notes-Chunk-Cursor', $nextCursor->toString());
				$response->addHeader('X-Notes-Chunk-Pending', (string)$pendingCount);
			} else {
				// The last chunk also lists the IDs of all other notes, so clients can detect deleted notes
				foreach (array_keys($notes) as $id) {
					$data[$id] ??= ['id' => $id];
				}
			}

			$data = array_values($data);
			$response->setData($data);
			$response->setLastModified((new \DateTime())->setTimestamp($timeStart));
			return ApiResponder::withETag($response, md5(json_encode($data, JSON_THROW_ON_ERROR)), $this->request);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function get(int $id, string $exclude = ''): Response {
		return $this->responder->respond(function () use ($id, $exclude): Response {
			$userId = $this->responder->getUserId();
			$note = $this->noteService->get($userId, $id);
			$meta = $this->metaService->get($userId, $note);
			$data = $this->serializeNote($note, $meta, array_filter(explode(',', $exclude)));

			return ApiResponder::withETag(new JSONResponse($data), $meta->getEtag(), $this->request);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function create(string $category = '', string $title = '', string $content = '', int $modified = 0, bool $favorite = false): Response {
		return $this->responder->respond(function () use ($category, $title, $content, $modified, $favorite): array {
			$userId = $this->responder->getUserId();
			if (trim($title) === '' && trim($content) !== '') {
				$title = NoteTitle::fromContent($content);
			}

			$note = $this->noteService->create($userId, $title, $category, $content);
			try {
				if ($modified > 0) {
					$note = $this->noteService->setModified($note, $modified);
				}
				if ($favorite) {
					$note = $this->noteService->setFavorite($userId, $note, true);
				}
			} catch (\Throwable $e) {
				$this->noteService->delete($note);
				throw $e;
			}

			return $this->serializeNote($note, $this->metaService->get($userId, $note));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function update(int $id, ?string $content = null, ?int $modified = null, ?string $title = null, ?string $category = null, ?bool $favorite = null): Response {
		return $this->responder->respond(function () use ($id, $content, $modified, $title, $category, $favorite): array {
			$userId = $this->responder->getUserId();
			$note = $this->noteService->get($userId, $id);

			$ifMatch = $this->request->getHeader('If-Match');
			if ($ifMatch !== '') {
				$meta = $this->metaService->get($userId, $note);
				if (!ApiResponder::etagMatches($ifMatch, $meta->getEtag())) {
					throw new PreconditionFailedException($this->serializeNote($note, $meta));
				}
			}

			if ($content !== null && $content !== $note->getContent()) {
				$note = $this->noteService->setContent($note, $content);
			}

			$titleChanged = $title !== null && $title !== $note->getTitle();
			$categoryChanged = $category !== null && $category !== $note->getSubFolderPath();
			if ($titleChanged || $categoryChanged) {
				$result = $this->noteService->move($userId, $note, $titleChanged ? $title : null, $categoryChanged ? $category : null);
				$note = $result['note'];
			}

			if ($modified !== null && $modified > 0 && $modified !== $note->getModified()) {
				$note = $this->noteService->setModified($note, $modified);
			}
			if ($favorite !== null && $favorite !== $note->isFavorite()) {
				$note = $this->noteService->setFavorite($userId, $note, $favorite);
			}

			return $this->serializeNote($note, $this->metaService->get($userId, $note));
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function destroy(int $id): Response {
		return $this->responder->respond(function () use ($id): array {
			$userId = $this->responder->getUserId();
			$this->noteService->delete($this->noteService->get($userId, $id));
			return [];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getSettings(): Response {
		return $this->responder->respond(fn (): array => $this->settingsService->getAll($this->responder->getUserId()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function setSettings(): Response {
		return $this->responder->respond(function (): array {
			$userId = $this->responder->getUserId();
			$this->settingsService->set($userId, $this->request->getParams());
			// Create the note folder right away, so clients can rely on its existence
			$this->folderService->getNotesFolder($userId);

			return $this->settingsService->getAll($userId);
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getAttachment(int $noteid, string $path = ''): Response {
		return $this->responder->respond(function () use ($noteid, $path): Response {
			$userId = $this->responder->getUserId();
			$file = $this->attachmentService->get($userId, $this->noteService->get($userId, $noteid), $path);
			$handle = $file->fopen('rb');
			if ($handle === false) {
				throw new \RuntimeException('The attachment can not be read');
			}

			$response = new StreamResponse($handle);
			$response->addHeader('Content-Type', $this->mimeTypeDetector->getSecureMimeType($file->getMimeType()));
			$response->addHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($file->getName()) . '"');
			$response->addHeader('Vary', 'Authorization, Cookie');
			$response->cacheFor(3600);
			return $response;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function uploadFile(int $noteid): Response {
		return $this->responder->respond(function () use ($noteid): array {
			$userId = $this->responder->getUserId();
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_readable((string)$upload['tmp_name'])) {
				throw new InvalidInputException('No file was uploaded');
			}

			$note = $this->noteService->get($userId, $noteid);
			$content = (string)file_get_contents((string)$upload['tmp_name']);
			$mimeType = $this->mimeTypeDetector->detectString($content);
			$link = $this->attachmentService->create($userId, $note, (string)$upload['name'], $mimeType, $content);

			return ['filename' => $link];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function deleteAttachment(int $noteid, string $path = ''): Response {
		return $this->responder->respond(function () use ($noteid, $path): array {
			$userId = $this->responder->getUserId();
			$this->attachmentService->delete($userId, $this->noteService->get($userId, $noteid), $path);
			return [];
		});
	}

	/**
	 * @param list<string> $exclude
	 */
	private function serializeNote(Note $note, Meta $meta, array $exclude = []): array {
		$data = [
			'id' => $note->getId(),
			'etag' => $meta->getEtag(),
			'readonly' => $note->isReadonly(),
			'modified' => $note->getModified(),
			'title' => $note->getTitle(),
			'category' => $note->getSubFolderPath(),
			'favorite' => $note->isFavorite(),
		];
		if (!in_array('content', $exclude, true)) {
			$data['content'] = $note->getContent();
		}

		foreach ($exclude as $attribute) {
			if ($attribute !== 'id' && in_array($attribute, self::NOTE_ATTRIBUTES, true)) {
				unset($data[$attribute]);
			}
		}

		return $data;
	}
}
