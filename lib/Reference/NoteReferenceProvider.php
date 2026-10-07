<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Reference;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\NoteTitle;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Link previews for links to notes of the web interface (e.g. in Text, Talk, Deck), and the smart picker entry
 * that finds notes with the unified search provider; only registered if the web interface is enabled
 */
class NoteReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private AppSettings $appSettings,
		private NoteService $noteService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-note';
	}

	public function getTitle(): string {
		return $this->l10n->t('QOwnNotes notes');
	}

	public function getOrder(): int {
		return 30;
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getSupportedSearchProviderIds(): array {
		return [Application::APP_ID];
	}

	public function matchReference(string $referenceText): bool {
		return $this->appSettings->isUiEnabled() && $this->getNoteId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$noteId = $this->getNoteId($referenceText);
		$user = $this->userSession->getUser();
		if ($noteId === null || $user === null) {
			return null;
		}

		try {
			$note = $this->noteService->get($user->getUID(), $noteId);
		} catch (\Throwable $e) {
			// Notes of other users, or deleted notes: the link stays a plain link
			$this->logger->debug('Note reference could not be resolved', ['exception' => $e]);
			return null;
		}

		$reference = new Reference($referenceText);
		$reference->setTitle($note->getTitle());
		$excerpt = NoteTitle::excerpt($note->getContent(), 200);
		$reference->setDescription($note->getSubFolderPath() === '' ? $excerpt : $note->getSubFolderPath() . ($excerpt === '' ? '' : ' · ' . $excerpt));
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl($referenceText);

		return $reference;
	}

	/**
	 * Notes are only visible to their owner, so the cache is per user
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}

	/**
	 * The note ID of a link to a note of the web interface, with or without "index.php"
	 */
	public function getNoteId(string $referenceText): ?int {
		return self::parseNoteId($this->urlGenerator->getAbsoluteURL('/'), $referenceText);
	}

	public static function parseNoteId(string $serverUrl, string $referenceText): ?int {
		$pattern = '#^' . preg_quote(rtrim($serverUrl, '/'), '#') . '(?:/index\.php)?/apps/' . Application::APP_ID . '/note/(\d+)(?:[/?\#].*)?$#';
		return preg_match($pattern, trim($referenceText), $matches) === 1 ? (int)$matches[1] : null;
	}
}
