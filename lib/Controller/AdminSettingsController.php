<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Stores the admin settings; admin-only and CSRF-protected (default for controllers)
 */
class AdminSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private AppSettings $appSettings,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function save(string $uiEnabled = '0'): RedirectResponse {
		$this->appSettings->setUiEnabled($uiEnabled === '1');

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
		);
	}
}
