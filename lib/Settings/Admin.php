<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Settings;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\TagDatabase;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Rendered as a plain HTML form, so it works without any frontend build
 */
class Admin implements ISettings {
	public function __construct(
		private AppSettings $appSettings,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'uiEnabled' => $this->appSettings->isUiEnabled(),
			'tagsAvailable' => TagDatabase::isAvailable(),
			'saveUrl' => $this->urlGenerator->linkToRoute('qownnotes.admin_settings.save'),
		], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
