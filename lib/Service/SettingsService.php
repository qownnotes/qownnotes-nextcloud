<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user settings
 */
class SettingsService {
	public const NOTES_PATH = 'notesPath';
	public const FILE_SUFFIX = 'fileSuffix';
	public const IGNORE_NOTE_SUB_FOLDERS = 'ignoreNoteSubFolders';
	public const SUB_FOLDERS_ENABLED = 'subfoldersEnabled';
	public const NOTE_HEADER_STYLE = 'noteHeaderStyle';
	public const CREATE_TAG_DATABASE = 'createTagDatabase';
	public const REWRITE_MEDIA_LINKS = 'rewriteMediaLinks';

	public const DEFAULT_NOTES_PATH = 'Notes';
	public const DEFAULT_FILE_SUFFIX = '.md';
	/** Same default as QOwnNotes Desktop (IGNORED_NOTE_SUBFOLDERS_DEFAULT) */
	public const DEFAULT_IGNORE_NOTE_SUB_FOLDERS = '^\.';

	public const HEADER_STYLE_ATX = 'atx';
	public const HEADER_STYLE_SETEXT = 'setext';

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Path of the note folder, relative to the user's files root, without leading or trailing slashes
	 */
	public function getNotesPath(string $userId): string {
		$path = $this->config->getUserValue($userId, Application::APP_ID, self::NOTES_PATH, '');
		if ($path !== '') {
			return $path;
		}

		// Take over the folder of the Nextcloud Notes app, so switching apps keeps the same notes
		$path = self::normalizePath($this->config->getUserValue($userId, 'notes', 'notesPath', ''));
		if ($path === '') {
			$path = self::DEFAULT_NOTES_PATH;
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::NOTES_PATH, $path);
		return $path;
	}

	public function getFileSuffix(string $userId): string {
		return self::normalizeFileSuffix(
			$this->config->getUserValue($userId, Application::APP_ID, self::FILE_SUFFIX, self::DEFAULT_FILE_SUFFIX)
		);
	}

	public function getIgnoreNoteSubFolders(string $userId): string {
		return $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			self::IGNORE_NOTE_SUB_FOLDERS,
			self::DEFAULT_IGNORE_NOTE_SUB_FOLDERS
		);
	}

	public function isSubfoldersEnabled(string $userId): bool {
		return $this->getBool($userId, self::SUB_FOLDERS_ENABLED, true);
	}

	public function getNoteHeaderStyle(string $userId): string {
		$style = $this->config->getUserValue($userId, Application::APP_ID, self::NOTE_HEADER_STYLE, self::HEADER_STYLE_ATX);
		return $style === self::HEADER_STYLE_SETEXT ? self::HEADER_STYLE_SETEXT : self::HEADER_STYLE_ATX;
	}

	public function isCreateTagDatabase(string $userId): bool {
		return $this->getBool($userId, self::CREATE_TAG_DATABASE, true);
	}

	public function isRewriteMediaLinks(string $userId): bool {
		return $this->getBool($userId, self::REWRITE_MEDIA_LINKS, true);
	}

	/**
	 * @return array{notesPath: string, fileSuffix: string, ignoreNoteSubFolders: string, subfoldersEnabled: bool, noteHeaderStyle: string, createTagDatabase: bool, rewriteMediaLinks: bool}
	 */
	public function getAll(string $userId): array {
		return [
			self::NOTES_PATH => $this->getNotesPath($userId),
			self::FILE_SUFFIX => $this->getFileSuffix($userId),
			self::IGNORE_NOTE_SUB_FOLDERS => $this->getIgnoreNoteSubFolders($userId),
			self::SUB_FOLDERS_ENABLED => $this->isSubfoldersEnabled($userId),
			self::NOTE_HEADER_STYLE => $this->getNoteHeaderStyle($userId),
			self::CREATE_TAG_DATABASE => $this->isCreateTagDatabase($userId),
			self::REWRITE_MEDIA_LINKS => $this->isRewriteMediaLinks($userId),
		];
	}

	/**
	 * Validates and stores the given settings; unknown keys are ignored
	 *
	 * @param array<string, mixed> $values
	 */
	public function set(string $userId, array $values): void {
		foreach ($values as $key => $value) {
			$stored = match ($key) {
				self::NOTES_PATH => self::normalizePath((string)$value) ?: self::DEFAULT_NOTES_PATH,
				self::FILE_SUFFIX => self::normalizeFileSuffix((string)$value),
				self::IGNORE_NOTE_SUB_FOLDERS => trim((string)$value),
				self::SUB_FOLDERS_ENABLED, self::CREATE_TAG_DATABASE, self::REWRITE_MEDIA_LINKS => self::toBool($value) ? 'yes' : 'no',
				self::NOTE_HEADER_STYLE => $value === self::HEADER_STYLE_SETEXT ? self::HEADER_STYLE_SETEXT : self::HEADER_STYLE_ATX,
				default => null,
			};

			if ($stored !== null) {
				$this->config->setUserValue($userId, Application::APP_ID, $key, $stored);
			}
		}
	}

	/**
	 * Normalizes a relative path: unifies separators, resolves "." and ".." and removes empty segments,
	 * so the result can never point outside the folder it is relative to
	 */
	public static function normalizePath(string $path): string {
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

	public static function normalizeFileSuffix(string $suffix): string {
		$suffix = ltrim((string)preg_replace('/[^A-Za-z0-9.-]/', '', $suffix), '.');
		if ($suffix === '' || $suffix === 'custom') {
			return self::DEFAULT_FILE_SUFFIX;
		}

		return '.' . strtolower($suffix);
	}

	private function getBool(string $userId, string $key, bool $default): bool {
		$value = $this->config->getUserValue($userId, Application::APP_ID, $key, $default ? 'yes' : 'no');
		return self::toBool($value);
	}

	private static function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower(trim((string)$value)), ['yes', '1', 'true', 'on'], true);
	}
}
