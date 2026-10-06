<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\SettingsService;
use OCA\QOwnNotes\Service\TagDatabase;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IUserSession;

/**
 * Published under the "qownnotes" key only. Publishing "notes" would collide with the Nextcloud Notes app.
 */
class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
		private IUserSession $userSession,
		private AppSettings $appSettings,
		private SettingsService $settingsService,
	) {
	}

	public function getCapabilities(): array {
		$user = $this->userSession->getUser();
		$tagsAvailable = TagDatabase::isAvailable();

		return [
			Application::APP_ID => [
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'notes_api_version' => Application::NOTES_API_VERSIONS,
				'qownnotes_api_version' => Application::QOWNNOTES_API_VERSIONS,
				'api_base' => Application::API_BASE,
				'ui_enabled' => $this->appSettings->isUiEnabled(),
				'notes_path' => $user !== null ? $this->settingsService->getNotesPath($user->getUID()) : null,
				'versions_app' => $this->appManager->isEnabledForUser('files_versions', $user),
				'trash_app' => $this->appManager->isEnabledForUser('files_trashbin', $user),
				'tags' => [
					'available' => $tagsAvailable,
					'writable_schema_versions' => $tagsAvailable ? TagDatabase::WRITABLE_SCHEMA_VERSIONS : [],
				],
			],
		];
	}
}
