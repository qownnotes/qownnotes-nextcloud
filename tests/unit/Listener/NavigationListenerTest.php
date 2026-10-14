<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Listener;

use OCA\QOwnNotes\Listener\NavigationListener;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\SettingsService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NavigationListenerTest extends TestCase {
	public static function cases(): array {
		return [
			'UI enabled, not hidden' => [true, 'no', true, true],
			'UI enabled, hidden by the user' => [true, 'yes', true, false],
			'API-only mode' => [false, 'no', true, false],
			'API-only mode, hidden by the user' => [false, 'yes', true, false],
			'no user session' => [true, 'yes', false, true],
		];
	}

	#[DataProvider('cases')]
	public function testEntryIsOnlyAddedIfVisible(bool $uiEnabled, string $hideNavigation, bool $loggedIn, bool $expected): void {
		$navigationManager = $this->createMock(INavigationManager::class);
		$navigationManager->expects($expected ? $this->once() : $this->never())->method('add')
			->with($this->callback(static fn (array $entry): bool => $entry['id'] === 'qownnotes'
				&& $entry['app'] === 'qownnotes'
				&& $entry['type'] === 'link'
				&& $entry['href'] === '/apps/qownnotes/'
				&& $entry['name'] === 'QOwnNotes'));

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('qownnotes.page.index')->willReturn('/apps/qownnotes/');
		$urlGenerator->method('imagePath')->willReturn('/apps/qownnotes/img/app.svg');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($loggedIn ? $user : null);

		$appSettings = $this->createMock(AppSettings::class);
		$appSettings->method('isUiEnabled')->willReturn($uiEnabled);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnMap([
			['alice', 'qownnotes', 'hideNavigation', 'no', $hideNavigation],
		]);

		$listener = new NavigationListener($navigationManager, $urlGenerator, $l10nFactory, $userSession, $appSettings, new SettingsService($config));
		$listener->handle(new LoadAdditionalEntriesEvent());
	}
}
