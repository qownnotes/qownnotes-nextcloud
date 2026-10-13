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
	public const KEY_TAG_DATABASE_MAX_SIZE = 'tag_database_max_size_mib';
	public const DEFAULT_TAG_DATABASE_MAX_SIZE_MIB = 50;
	/** Upper bound, because the database is copied to a temporary file and parsed within one request */
	public const MAX_TAG_DATABASE_MAX_SIZE_MIB = 1024;

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

	/**
	 * Maximum size of a note folder database "notes.sqlite" in MiB; invalid values fall back to the default
	 */
	public function getTagDatabaseMaxSizeMib(): int {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, self::KEY_TAG_DATABASE_MAX_SIZE, (string)self::DEFAULT_TAG_DATABASE_MAX_SIZE_MIB));
		if (!preg_match('/^\d+$/', $value) || (int)$value < 1 || (int)$value > self::MAX_TAG_DATABASE_MAX_SIZE_MIB) {
			return self::DEFAULT_TAG_DATABASE_MAX_SIZE_MIB;
		}

		return (int)$value;
	}

	public function setTagDatabaseMaxSizeMib(int $mib): void {
		$mib = max(1, min(self::MAX_TAG_DATABASE_MAX_SIZE_MIB, $mib));
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_TAG_DATABASE_MAX_SIZE, (string)$mib);
	}
}
