<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Service\SubFolderService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Note subfolders (QOwnNotes API 1.1)
 */
class SubFoldersApiController extends ApiController {
	public function __construct(
		IRequest $request,
		private ApiResponder $responder,
		private SubFolderService $subFolderService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function index(): Response {
		return $this->responder->respond(fn (): array => $this->subFolderService->getTree($this->responder->getUserId()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function create(string $path = ''): Response {
		return $this->responder->respond(fn (): array => $this->subFolderService->create($this->responder->getUserId(), $path));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function move(string $path = '', string $newPath = ''): Response {
		return $this->responder->respond(fn (): array => $this->subFolderService->move($this->responder->getUserId(), $path, $newPath));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	public function destroy(string $path = ''): Response {
		return $this->responder->respond(fn (): array => $this->subFolderService->delete($this->responder->getUserId(), $path));
	}
}
