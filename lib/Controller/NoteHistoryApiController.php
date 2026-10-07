<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Model\NoteLocation;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\SettingsService;
use OCA\QOwnNotes\Service\TagService;
use OCA\QOwnNotes\Service\TrashService;
use OCA\QOwnNotes\Service\VersionService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Note details, versions and the trash of the note folder, addressed by note ID (QOwnNotes API 1.1);
 * the path based endpoints of QOwnNotes API 1.0 stay for QOwnNotes Desktop
 */
class NoteHistoryApiController extends ApiController {
	public function __construct(
		IRequest $request,
		private ApiResponder $responder,
		private IUserManager $userManager,
		private NoteService $noteService,
		private NoteFolderService $folderService,
		private SettingsService $settingsService,
		private VersionService $versionService,
		private TrashService $trashService,
		private TagService $tagService,
	) {
		parent::__construct(Application::APP_ID, $request, 'GET, POST', 'Authorization, Content-Type, Accept, ' . NotesApiController::RELINK_TAGS_HEADER);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function info(int $id): Response {
		return $this->responder->respond(function () use ($id): array {
			$userId = $this->responder->getUserId();
			$note = $this->noteService->get($userId, $id);
			$user = $this->getUser($userId);

			return [
				'id' => $note->getId(),
				'fileName' => $note->getFileName(),
				'subFolderPath' => $note->getSubFolderPath(),
				'path' => $this->getUserPath($userId, $note),
				'size' => $note->getSize(),
				'modified' => $note->getModified(),
				'readonly' => $note->isReadonly(),
				'versionsAvailable' => $this->versionService->isAvailable($user),
				'trashAvailable' => $this->trashService->isAvailable($user),
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function versions(int $id): Response {
		return $this->responder->respond(function () use ($id): array {
			$userId = $this->responder->getUserId();
			$note = $this->noteService->get($userId, $id);

			return [
				'id' => $note->getId(),
				'versions' => $this->versionService->getVersions($this->getUser($userId), $this->getUserPath($userId, $note)),
			];
		});
	}

	/**
	 * Notes deleted from the note folder and its subfolders, newest first
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function trash(): Response {
		return $this->responder->respond(function (): array {
			$userId = $this->responder->getUserId();
			$notesPath = $this->settingsService->getNotesPath($userId);
			$items = $this->trashService->getTrashedNotes(
				$this->getUser($userId),
				$notesPath,
				$this->folderService->getNoteExtensions($userId),
				true,
				'mtime',
				true,
			);

			$notes = [];
			foreach ($items as $item) {
				$subFolderPath = self::getSubFolderPath($notesPath, $item['originalLocation']);
				if ($subFolderPath === null || ($subFolderPath !== '' && $this->folderService->isIgnoredSubFolderPath($userId, $subFolderPath))) {
					continue;
				}

				$notes[] = [
					'title' => $item['noteName'],
					'fileName' => $item['fileName'],
					'subFolderPath' => $subFolderPath,
					'originalLocation' => $item['originalLocation'],
					'deleted' => $item['timestamp'],
					'content' => $item['data'],
				];
			}

			return ['notes' => $notes];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function restore(string $originalLocation = '', int $deleted = 0): Response {
		return $this->responder->respond(function () use ($originalLocation, $deleted): array {
			$userId = $this->responder->getUserId();
			$notesPath = $this->settingsService->getNotesPath($userId);
			$location = SettingsService::normalizePath($originalLocation);
			if (self::getSubFolderPath($notesPath, $location) === null || $deleted <= 0) {
				throw new InvalidInputException('Only notes of the note folder can be restored');
			}

			$restored = $this->trashService->restore($this->getUser($userId), $location, $deleted);
			if ($restored === null) {
				throw new NoteNotFoundException('The note is not in the trash');
			}

			$note = $this->findRestoredNote($userId, $restored);
			if ($note !== null && $this->request->getHeader(NotesApiController::RELINK_TAGS_HEADER) === '1') {
				$this->tagService->markNoteNotStale($userId, NoteLocation::fromNote($note));
			}

			return ['id' => $note?->getId()];
		});
	}

	/**
	 * The subfolder of a path in the user's files, or null if it is outside of the note folder
	 */
	public static function getSubFolderPath(string $notesPath, string $location): ?string {
		$location = trim($location, '/');
		$prefix = $notesPath === '' ? '' : $notesPath . '/';
		if (!str_starts_with($location, $prefix)) {
			return null;
		}

		$directory = dirname(substr($location, strlen($prefix)));
		return $directory === '.' ? '' : $directory;
	}

	private function findRestoredNote(string $userId, string $location): ?Note {
		try {
			$node = $this->folderService->getUserFolder($userId)->get($location);
			return $node instanceof File ? $this->noteService->get($userId, $node->getId()) : null;
		} catch (\Throwable) {
			// The note was restored under another name or into an ignored folder
			return null;
		}
	}

	private function getUserPath(string $userId, Note $note): string {
		return ltrim((string)$this->folderService->getUserFolder($userId)->getRelativePath($note->getFile()->getPath()), '/');
	}

	private function getUser(string $userId): IUser {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new \RuntimeException('Unknown user');
		}

		return $user;
	}
}
