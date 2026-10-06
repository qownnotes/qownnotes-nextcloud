<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\AppInfo;

use OCA\QOwnNotes\Capabilities;
use OCA\QOwnNotes\Middleware\UiEnabledMiddleware;
use OCA\QOwnNotes\Service\AppSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Application extends App implements IBootstrap {
	public const APP_ID = 'qownnotes';

	/** Supported Nextcloud Notes API versions (highest minor version per major version) */
	public const NOTES_API_VERSIONS = ['1.4'];

	/** Supported QOwnNotes API versions (highest minor version per major version) */
	public const QOWNNOTES_API_VERSIONS = ['1.1'];

	/** Base path of all APIs, relative to the server URL */
	public const API_BASE = '/index.php/apps/qownnotes/api/v1/';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerMiddleware(UiEnabledMiddleware::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn($this->registerNavigation(...));
	}

	/**
	 * The navigation entry is registered here instead of in info.xml, so it can be hidden in API-only mode
	 */
	private function registerNavigation(
		INavigationManager $navigationManager,
		IURLGenerator $urlGenerator,
		IFactory $l10nFactory,
		AppSettings $appSettings,
	): void {
		if (!$appSettings->isUiEnabled()) {
			return;
		}

		$navigationManager->add(static function () use ($urlGenerator, $l10nFactory): array {
			return [
				'id' => self::APP_ID,
				'app' => self::APP_ID,
				'order' => 10,
				'href' => $urlGenerator->linkToRoute('qownnotes.page.index'),
				'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
				'name' => $l10nFactory->get(self::APP_ID)->t('QOwnNotes'),
				'type' => 'link',
			];
		});
	}
}
