<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Search;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\NoteTitle;
use OCA\QOwnNotes\Service\TagService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Unified search for notes: all words of the search term have to be in the title, the text or the tag paths
 * of a note, so "work meeting" finds the note "Meeting" with the tag "Work"
 */
class NotesSearchProvider implements IProvider {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private AppSettings $appSettings,
		private NoteService $noteService,
		private TagService $tagService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('QOwnNotes');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// The results open the web interface, so the provider is hidden in API-only mode
		if (!$this->appSettings->isUiEnabled()) {
			return null;
		}

		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 35;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$words = self::splitWords($query->getTerm());
		if ($words === [] || !$this->appSettings->isUiEnabled()) {
			return SearchResult::complete($this->getName(), []);
		}

		$userId = $user->getUID();
		$notes = array_values($this->noteService->getAll($userId));
		$tagPaths = $this->getTagPaths($userId);

		$matches = array_filter($notes, static function (Note $note) use ($words, $tagPaths): bool {
			$key = $note->getSubFolderPath() . "\0" . $note->getFileName();
			return self::matches($words, $note->getTitle(), $note->getContent(), $tagPaths[$key] ?? []);
		});
		usort($matches, static fn (Note $a, Note $b): int => $b->getModified() <=> $a->getModified());

		$offset = max(0, (int)$query->getCursor());
		$page = array_slice($matches, $offset, $query->getLimit());
		$iconUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/text.svg'));

		$entries = array_map(function (Note $note) use ($tagPaths, $iconUrl): SearchResultEntry {
			$key = $note->getSubFolderPath() . "\0" . $note->getFileName();
			$details = array_map(static fn (string $path): string => '#' . $path, $tagPaths[$key] ?? []);
			if ($note->getSubFolderPath() !== '') {
				array_unshift($details, $note->getSubFolderPath());
			}
			$excerpt = NoteTitle::excerpt($note->getContent(), 80);
			if ($excerpt !== '') {
				$details[] = $excerpt;
			}

			return new SearchResultEntry(
				'',
				$note->getTitle(),
				implode(' · ', $details),
				$this->urlGenerator->linkToRouteAbsolute('qownnotes.page.indexnote', ['id' => $note->getId()]),
				$iconUrl,
			);
		}, $page);

		return SearchResult::paginated($this->getName(), $entries, $offset + count($page));
	}

	/**
	 * @return list<string> the lowercase words of the search term
	 */
	public static function splitWords(string $term): array {
		$words = preg_split('/\s+/u', mb_strtolower(trim($term), 'UTF-8')) ?: [];
		return array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
	}

	/**
	 * @param list<string> $words lowercase words
	 * @param list<string> $tagPaths tag paths of the note, e.g. "Work/Project A"
	 */
	public static function matches(array $words, string $title, string $content, array $tagPaths): bool {
		$text = mb_strtolower($title . "\n" . $content . "\n" . implode("\n", $tagPaths), 'UTF-8');
		foreach ($words as $word) {
			// "#tag" only searches the tags
			if (str_starts_with($word, '#') && $word !== '#') {
				$tagWord = substr($word, 1);
				$found = array_filter($tagPaths, static fn (string $path): bool => str_contains(mb_strtolower($path, 'UTF-8'), $tagWord));
				if ($found === []) {
					return false;
				}
				continue;
			}
			if (!str_contains($text, $word)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, list<string>> tag paths keyed by subfolder path and file name of the note
	 */
	private function getTagPaths(string $userId): array {
		try {
			$snapshot = $this->tagService->getSnapshot($userId);
		} catch (\Throwable $e) {
			// No pdo_sqlite or a broken notes.sqlite: search without tags
			$this->logger->debug('Tags are not available for the search', ['exception' => $e]);
			return [];
		}

		$paths = [];
		foreach ($snapshot->links as $link) {
			if (!$link['stale'] && isset($snapshot->tags[$link['tagId']])) {
				$paths[$link['subFolderPath'] . "\0" . $link['fileName']][] = implode('/', $snapshot->getPath($link['tagId']));
			}
		}

		return array_map(static fn (array $list): array => array_values(array_unique($list)), $paths);
	}
}
