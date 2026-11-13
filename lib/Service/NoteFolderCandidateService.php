<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Finds folders in the user's files that probably already contain notes, besides the configured note folder,
 * e.g. a localized "Notizen" folder of the Nextcloud Notes app next to the "Notes" folder of QOwnNotes Desktop.
 * The candidates are only offered to the user; nothing is moved or switched automatically.
 */
class NoteFolderCandidateService {
	public const SOURCE_NOTES_APP = 'notesApp';
	public const SOURCE_DEFAULT_NAME = 'defaultName';
	public const SOURCE_TAG_DATABASE = 'tagDatabase';

	public const MAX_CANDIDATES = 10;

	/** Notes are counted up to this number; larger counts are reported as capped */
	public const MAX_NOTE_COUNT = 1000;

	/** Extensions of note files in candidate folders, in addition to the user's file suffix */
	public const NOTE_EXTENSIONS = ['md', 'txt', 'markdown', 'note', 'org'];

	/**
	 * Translations of the default folder name "Notes" of the Nextcloud Notes app, which creates the folder with
	 * the user's language
	 */
	public const DEFAULT_FOLDER_NAMES = [
		'Notes', 'Notizen', 'Notas', 'Note', 'Notities', 'Noter', 'Notater', 'Notat', 'Notatki', 'Anteckningar',
		'Muistiinpanot', 'Jegyzetek', 'Poznámky', 'Notlar', 'Notițe', 'Bilješke', 'Beležka', 'Catatan', 'Ghi chép',
		'Märkmed', 'Piezīmes', 'Užrašai', 'Oharrak', 'Notoj', 'Nòtas', 'Nótaí', 'Notennoù', 'Nòtaichean', 'Nodiadau',
		'Minnispunktar', 'Shënime', 'Qeydlər', 'Nota', 'Mazingatio', 'Tizmilin', 'Σημειώσεις', 'Заметки', 'Нотатки',
		'Бележки', 'Белешке', 'Белешки', 'Нататкі', 'Тэмдэглэл', 'Նոթեր', 'הערות', 'الملاحظات', 'ئىزاھاتلار',
		"یادداشت\u{200C}ها", 'নোট', 'குறிப்புகள்', 'සටහන්', 'โน้ต', 'ჩანაწერები', "កំណត់\u{200B}ចំណាំ", 'ノート', '笔记',
		'筆記', '메모',
	];

	/** Found note folder databases that are examined at most */
	private const MAX_TAG_DATABASES = 50;

	/** Folder listings for counting notes per request, so huge folder trees can't slow down the request */
	private const MAX_FOLDER_LISTINGS = 1000;

	/** @var array<string, true>|null */
	private static ?array $defaultFolderNameKeys = null;

	public function __construct(
		private NoteFolderService $folders,
		private SettingsService $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Folders with notes outside the user's note folder, most likely note folders first
	 *
	 * @return list<array{path: string, noteCount: int, noteCountCapped: bool, hasTagDatabase: bool, sources: list<string>}>
	 */
	public function getCandidates(string $userId): array {
		$userFolder = $this->folders->getUserFolder($userId);
		$notesPath = $this->settings->getNotesPath($userId);

		/** @var array<string, list<string>> $sources sources of each folder path */
		$sources = [];
		$addSource = static function (string $path, string $source) use (&$sources): void {
			if ($path !== '' && !in_array($source, $sources[$path] ?? [], true)) {
				$sources[$path][] = $source;
			}
		};

		$addSource($this->settings->getNotesAppNotesPath($userId), self::SOURCE_NOTES_APP);
		foreach ($userFolder->getDirectoryListing() as $node) {
			if ($node instanceof Folder && self::isDefaultFolderName($node->getName())) {
				$addSource($node->getName(), self::SOURCE_DEFAULT_NAME);
			}
		}
		foreach ($this->findTagDatabaseFolders($userFolder) as $path) {
			$addSource($path, self::SOURCE_TAG_DATABASE);
		}

		// Count the most likely note folders first, in case the listing budget runs out
		uksort($sources, static fn (string|int $a, string|int $b): int => self::priority($sources[$b]) <=> self::priority($sources[$a]));

		$extensions = array_values(array_unique([...self::NOTE_EXTENSIONS, ...$this->folders->getNoteExtensions($userId)]));
		$listingBudget = self::MAX_FOLDER_LISTINGS;
		$candidates = [];
		foreach ($sources as $path => $pathSources) {
			// Numeric folder names become integer array keys
			$path = (string)$path;
			if (self::overlaps($path, $notesPath) || $this->folders->isIgnoredSubFolderPath($userId, $path)) {
				continue;
			}

			try {
				$folder = $userFolder->get($path);
			} catch (NotFoundException|NotPermittedException) {
				continue;
			}
			if (!$folder instanceof Folder) {
				continue;
			}

			$hasTagDatabase = $folder->nodeExists(NoteFolderService::TAG_DATABASE_FILE_NAME);
			[$noteCount, $capped] = $this->countNotes($userId, $folder, $extensions, $listingBudget);
			if ($noteCount === 0 && !$hasTagDatabase) {
				continue;
			}

			if ($hasTagDatabase && !in_array(self::SOURCE_TAG_DATABASE, $pathSources, true)) {
				$pathSources[] = self::SOURCE_TAG_DATABASE;
			}

			$candidates[] = [
				'path' => $path,
				'noteCount' => $noteCount,
				'noteCountCapped' => $capped,
				'hasTagDatabase' => $hasTagDatabase,
				'sources' => $pathSources,
			];
		}

		usort($candidates, static fn (array $a, array $b): int => [self::priority($b['sources']), $b['noteCount'], strtolower($a['path']), $a['path']]
			<=> [self::priority($a['sources']), $a['noteCount'], strtolower($b['path']), $b['path']]);

		return array_slice($candidates, 0, self::MAX_CANDIDATES);
	}

	/**
	 * Whether a folder name is a default name of the Nextcloud Notes folder, ignoring case and Unicode normalization
	 */
	public static function isDefaultFolderName(string $name): bool {
		if (self::$defaultFolderNameKeys === null) {
			self::$defaultFolderNameKeys = [];
			foreach (self::DEFAULT_FOLDER_NAMES as $defaultName) {
				self::$defaultFolderNameKeys[self::nameKey($defaultName)] = true;
			}
		}

		return isset(self::$defaultFolderNameKeys[self::nameKey($name)]);
	}

	/**
	 * Whether one path is the same as, inside of, or an ancestor of the other path
	 */
	public static function overlaps(string $path, string $otherPath): bool {
		return $path === $otherPath
			|| str_starts_with($path . '/', $otherPath . '/')
			|| str_starts_with($otherPath . '/', $path . '/');
	}

	private static function nameKey(string $name): string {
		if (class_exists(\Normalizer::class)) {
			$name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
		}

		// Case folding also matches the final sigma of Greek names
		return mb_convert_case(trim($name), MB_CASE_FOLD, 'UTF-8');
	}

	/**
	 * Folders with the Nextcloud Notes app's folder and a note folder database are most likely note folders
	 *
	 * @param list<string> $sources
	 */
	private static function priority(array $sources): int {
		return (int)in_array(self::SOURCE_NOTES_APP, $sources, true) + (int)in_array(self::SOURCE_TAG_DATABASE, $sources, true);
	}

	/**
	 * Paths of the folders with a note folder database "notes.sqlite", relative to the user's files root.
	 * The search uses the file cache instead of walking through the folders.
	 *
	 * @return list<string>
	 */
	private function findTagDatabaseFolders(Folder $userFolder): array {
		try {
			$nodes = $userFolder->search(NoteFolderService::TAG_DATABASE_FILE_NAME);
		} catch (\Exception $e) {
			$this->logger->warning('Searching note folder databases failed', ['exception' => $e]);
			return [];
		}

		$paths = [];
		foreach ($nodes as $node) {
			// The search matches names that contain the term, like "notes.sqlite-journal"
			if (!$node instanceof File || $node->getName() !== NoteFolderService::TAG_DATABASE_FILE_NAME) {
				continue;
			}

			$path = $userFolder->getRelativePath(dirname($node->getPath()));
			if ($path !== null) {
				$paths[] = SettingsService::normalizePath($path);
			}
			if (count($paths) >= self::MAX_TAG_DATABASES) {
				break;
			}
		}

		return $paths;
	}

	/**
	 * Counts the note files of a folder and its non-ignored subfolders, up to MAX_NOTE_COUNT
	 *
	 * @param list<string> $extensions lowercase extensions without a dot
	 * @param int $listingBudget remaining folder listings of the request
	 * @return array{0: int, 1: bool} the number of notes, and whether counting stopped early
	 */
	private function countNotes(string $userId, Folder $folder, array $extensions, int &$listingBudget): array {
		$count = 0;
		$queue = [$folder];
		while ($queue !== []) {
			if ($listingBudget <= 0) {
				return [$count, true];
			}
			$listingBudget--;

			$current = array_shift($queue);
			try {
				$nodes = $current->getDirectoryListing();
			} catch (NotFoundException|NotPermittedException) {
				continue;
			}

			foreach ($nodes as $node) {
				$name = $node->getName();
				if ($node instanceof Folder) {
					if (!$this->folders->isIgnoredFolderName($userId, $name)) {
						$queue[] = $node;
					}
					continue;
				}

				if ($name !== '' && $name[0] !== '.' && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
					$count++;
					if ($count >= self::MAX_NOTE_COUNT) {
						return [$count, true];
					}
				}
			}
		}

		return [$count, false];
	}
}
