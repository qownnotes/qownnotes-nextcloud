<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Attribute\RequiresUi;
use OCA\QOwnNotes\Service\TagDatabase;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

#[RequiresUi]
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Entry point of the web interface; client-side routes (/note/{id}, /folder/{path}) are served by the same page
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('config', [
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'tagsAvailable' => TagDatabase::isAvailable(),
		]);
		// The styles are bundled into the script
		Util::addScript(Application::APP_ID, 'qownnotes-main');

		$response = new TemplateResponse(Application::APP_ID, 'main');
		$policy = new ContentSecurityPolicy();
		// Allow images from any https source in the note preview, like other Nextcloud editors
		$policy->addAllowedImageDomain('https://*');
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
