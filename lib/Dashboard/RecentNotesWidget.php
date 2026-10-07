<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Dashboard;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\NoteTitle;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The recently changed notes, favorites first; rendered by the dashboard app itself (API widget)
 */
class RecentNotesWidget implements IAPIWidgetV2, IIconWidget, IConditionalWidget, IButtonWidget {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private AppSettings $appSettings,
		private NoteService $noteService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-recent';
	}

	public function getTitle(): string {
		return $this->l10n->t('Recent notes');
	}

	public function getOrder(): int {
		return 30;
	}

	public function getIconClass(): string {
		return 'icon-qownnotes';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('qownnotes.page.index');
	}

	public function load(): void {
	}

	/**
	 * The widget belongs to the web interface, so it is not shown in API-only mode
	 */
	public function isEnabled(): bool {
		return $this->appSettings->isUiEnabled();
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->urlGenerator->linkToRouteAbsolute('qownnotes.page.index'), $this->l10n->t('All notes')),
		];
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		try {
			$notes = array_values($this->noteService->getAll($userId));
		} catch (\Throwable $e) {
			$this->logger->warning('The notes for the dashboard could not be loaded', ['exception' => $e]);
			$notes = [];
		}

		usort($notes, static fn (Note $a, Note $b): int => [$b->isFavorite(), $b->getModified()] <=> [$a->isFavorite(), $a->getModified()]);
		$iconUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/text.svg'));

		$items = [];
		foreach (array_slice($notes, 0, $limit) as $note) {
			$subtitle = NoteTitle::excerpt($note->getContent());
			if ($note->getSubFolderPath() !== '') {
				$subtitle = $note->getSubFolderPath() . ($subtitle === '' ? '' : ' · ' . $subtitle);
			}

			$items[] = new WidgetItem(
				$note->getTitle(),
				$subtitle,
				$this->urlGenerator->linkToRouteAbsolute('qownnotes.page.indexnote', ['id' => $note->getId()]),
				$iconUrl,
				(string)$note->getModified(),
			);
		}

		return new WidgetItems($items, $this->l10n->t('No notes yet'));
	}
}
