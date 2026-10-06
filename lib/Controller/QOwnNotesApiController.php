<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\SettingsService;
use OCA\QOwnNotes\Service\TrashService;
use OCA\QOwnNotes\Service\VersionService;
use OCP\App\IAppManager;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The API of the qownnotesapi app for QOwnNotes Desktop and Android (QOwnNotes API 1.0); request parameters and
 * response fields must stay compatible, including the lenient error handling with HTTP status 200
 */
class QOwnNotesApiController extends ApiController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IAppManager $appManager,
		private IConfig $config,
		private NoteFolderService $folderService,
		private VersionService $versionService,
		private TrashService $trashService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getAppInfo(string $notes_path = ''): JSONResponse {
		$user = $this->getUser();
		$notesPathExists = false;
		$notesPath = SettingsService::normalizePath($notes_path);
		if ($notesPath !== '') {
			$userFolder = $this->folderService->getUserFolder($user->getUID());
			$notesPathExists = $userFolder->nodeExists($notesPath) && $userFolder->get($notesPath) instanceof Folder;
		}

		return new JSONResponse([
			'user' => $user->getUID(),
			'versions_app' => $this->versionService->isAvailable($user),
			'trash_app' => $this->trashService->isAvailable($user),
			'versioning' => true,
			'app_version' => $this->appManager->getAppVersion(Application::APP_ID),
			'server_version' => (string)$this->config->getSystemValue('version', ''),
			'notes_path_exists' => $notesPathExists,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getAllVersions(string $file_name = ''): JSONResponse {
		$errorMessages = [];
		$versions = [];
		try {
			$versions = $this->versionService->getVersions($this->getUser(), SettingsService::normalizePath($file_name));
		} catch (Throwable $e) {
			$this->logger->debug('Note versions could not be loaded', ['exception' => $e]);
			$errorMessages[] = $e instanceof \OCA\QOwnNotes\Exception\NoteNotFoundException
				? 'Requested file was not found!'
				: 'An error happened: ' . $e->getMessage();
		}

		return new JSONResponse([
			'file_name' => $file_name,
			'versions' => $versions,
			'error_messages' => $errorMessages,
		]);
	}

	/**
	 * @param string|list<string> $extensions additional note file extensions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function getTrashedNotes(string $dir = '', mixed $extensions = [], string $sort = 'mtime', string $sortdirection = '', bool $recursive = false): JSONResponse {
		$directory = SettingsService::normalizePath($dir);
		$extensions = array_merge(NoteFolderService::DEFAULT_NOTE_EXTENSIONS, is_array($extensions) ? array_map('strval', $extensions) : []);

		$notes = [];
		try {
			$notes = $this->trashService->getTrashedNotes($this->getUser(), $directory, $extensions, $recursive, $sort, $sortdirection !== 'asc');
		} catch (Throwable $e) {
			$this->logger->debug('Trashed notes could not be loaded', ['exception' => $e]);
		}

		return new JSONResponse([
			'directory' => $directory,
			'notes' => $notes,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function restoreTrashedNote(string $file_name = '', int $timestamp = 0): JSONResponse {
		$fileName = basename(str_replace('\\', '/', $file_name));
		$restoredLocation = null;
		try {
			$restoredLocation = $this->trashService->restore($this->getUser(), SettingsService::normalizePath($file_name), $timestamp);
		} catch (Throwable $e) {
			$this->logger->warning('Trashed note could not be restored', ['exception' => $e]);
		}

		return new JSONResponse([
			'result' => $restoredLocation !== null,
			'path' => '//' . $fileName . '.d' . $timestamp,
			'filename' => $fileName,
		]);
	}

	private function getUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No user is logged in');
		}

		return $user;
	}
}
