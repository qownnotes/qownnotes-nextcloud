<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\AppInfo;

use OCA\QOwnNotes\Capabilities;
use OCA\QOwnNotes\Dashboard\RecentNotesWidget;
use OCA\QOwnNotes\Listener\FilesScriptsListener;
use OCA\QOwnNotes\Listener\NavigationListener;
use OCA\QOwnNotes\Listener\UserDeletedListener;
use OCA\QOwnNotes\Middleware\UiEnabledMiddleware;
use OCA\QOwnNotes\Reference\NoteReferenceProvider;
use OCA\QOwnNotes\Search\NotesSearchProvider;
use OCA\QOwnNotes\Service\AppSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\IAppConfig;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'qownnotes';

	/** Supported Nextcloud Notes API versions (highest minor version per major version) */
	public const NOTES_API_VERSIONS = ['1.4'];

	/** Supported QOwnNotes API versions (highest minor version per major version) */
	public const QOWNNOTES_API_VERSIONS = ['1.1'];

	/** Base path of all APIs, relative to the server URL */
	public const API_BASE = '/index.php/apps/qownnotes/api/v1/';

	/**
	 * Per-user rate limit of expensive write endpoints that are not part of the note sync (tags, subfolders,
	 * attachment uploads, trash restores): at most this many requests per endpoint within RATE_LIMIT_PERIOD seconds
	 */
	public const RATE_LIMIT_WRITES = 300;
	public const RATE_LIMIT_PERIOD = 300;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerMiddleware(UiEnabledMiddleware::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(LoadAdditionalEntriesEvent::class, NavigationListener::class);

		// Integrations of the web interface; the dashboard widget and the search provider hide themselves in API-only mode
		$context->registerDashboardWidget(RecentNotesWidget::class);
		$context->registerSearchProvider(NotesSearchProvider::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, FilesScriptsListener::class);
		// Reference providers can't hide themselves in the smart picker, so they are only registered with the web interface
		if ((new AppSettings(Server::get(IAppConfig::class)))->isUiEnabled()) {
			$context->registerReferenceProvider(NoteReferenceProvider::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
