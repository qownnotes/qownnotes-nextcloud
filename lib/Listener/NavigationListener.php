<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Listener;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/**
 * Adds the navigation entry only for allowed users, unless the web interface is disabled or the user hides it.
 * The entry isn't declared in info.xml, so it can be conditional; the event is dispatched once the user is known.
 *
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
class NavigationListener implements IEventListener {
	public function __construct(
		private INavigationManager $navigationManager,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private IUserSession $userSession,
		private AppSettings $appSettings,
		private SettingsService $settingsService,
		private IAppManager $appManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent || !$this->appSettings->isUiEnabled()) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || !$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return;
		}
		if ($this->settingsService->isHideNavigation($user->getUID())) {
			return;
		}

		$this->navigationManager->add([
			'id' => Application::APP_ID,
			'app' => Application::APP_ID,
			'order' => 10,
			'href' => $this->urlGenerator->linkToRoute('qownnotes.page.index'),
			'icon' => $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
			'name' => $this->l10nFactory->get(Application::APP_ID)->t('QOwnNotes'),
			'type' => 'link',
		]);
	}
}
