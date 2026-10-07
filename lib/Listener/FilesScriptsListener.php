<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Listener;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\NoteFolderService;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds the "Open in QOwnNotes" action to the Files app; uses the public template event instead of the
 * Files app's own events, so only public APIs are needed
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class FilesScriptsListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
		private IInitialState $initialState,
		private AppSettings $appSettings,
		private SettingsService $settingsService,
		private NoteFolderService $folderService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn() || $event->getResponse()->getApp() !== 'files') {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || !$this->appSettings->isUiEnabled()) {
			return;
		}

		$userId = $user->getUID();
		$this->initialState->provideLazyInitialState('files', fn (): array => [
			'notesPath' => $this->settingsService->getNotesPath($userId),
			'extensions' => $this->folderService->getNoteExtensions($userId),
		]);
		Util::addInitScript(Application::APP_ID, 'qownnotes-files');
	}
}
