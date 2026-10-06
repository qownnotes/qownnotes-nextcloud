<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Server-wide settings of the app, managed by administrators
 */
class AppSettings {
	public const KEY_UI_ENABLED = 'ui_enabled';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Whether the web interface is enabled; if not, the app runs in API-only mode
	 */
	public function isUiEnabled(): bool {
		// Read as string, so values set with "occ config:app:set" (yes/no/1/0/true/false) work
		$value = $this->appConfig->getValueString(Application::APP_ID, self::KEY_UI_ENABLED, 'yes');
		return !in_array(strtolower(trim($value)), ['no', '0', 'false', 'off'], true);
	}

	public function setUiEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_UI_ENABLED, $enabled ? 'yes' : 'no');
	}
}
