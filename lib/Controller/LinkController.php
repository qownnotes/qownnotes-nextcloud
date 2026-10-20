<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Attribute\RequiresUi;
use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Service\LinkTitleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

#[RequiresUi]
class LinkController extends Controller {
	public function __construct(
		IRequest $request,
		private ApiResponder $responder,
		private LinkTitleService $titleService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function title(string $url = ''): Response {
		return $this->responder->respond(fn (): array => ['title' => $this->titleService->fetch($url)]);
	}
}
