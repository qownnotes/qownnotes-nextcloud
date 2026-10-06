<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

/**
 * Note name rules of QOwnNotes Desktop (Note::cleanupFileName, Note::handleNoteTextFileName)
 */
class NoteTitle {
	public const DEFAULT_TITLE = 'Note';
	public const MAX_LENGTH = 200;

	/**
	 * Makes a title usable as file name: removes characters that are invalid in file names on common
	 * filesystems, collapses whitespace and removes leading dots, so notes never become hidden files
	 */
	public static function sanitize(string $title): string {
		$title = (string)preg_replace('/[\/\\\\:]/u', '', $title);
		$title = (string)preg_replace('/[<>"|?*\x00-\x1F\x7F]/u', ' ', $title);
		$title = (string)preg_replace('/\s+/u', ' ', $title);
		$title = trim($title);
		$title = ltrim($title, '. ');
		$title = rtrim(mb_substr($title, 0, self::MAX_LENGTH, 'UTF-8'), '. ');

		return $title;
	}

	/**
	 * Derives the note name from the first line of the note text, like QOwnNotes Desktop
	 */
	public static function fromContent(string $content): string {
		$firstLine = preg_split('/\R/u', $content, 2)[0] ?? '';
		$firstLine = (string)preg_replace('/^#\s/u', '', trim($firstLine));
		$title = self::sanitize($firstLine);

		return $title === '' ? self::DEFAULT_TITLE : $title;
	}

	/**
	 * Creates the headline of a new note, like Note::createNoteHeader in QOwnNotes Desktop
	 */
	public static function createHeader(string $title, string $style = SettingsService::HEADER_STYLE_ATX): string {
		$title = trim($title);
		if ($style !== SettingsService::HEADER_STYLE_SETEXT) {
			return '# ' . $title . "\n\n";
		}

		return $title . "\n" . str_repeat('=', min(mb_strlen($title, 'UTF-8'), 40)) . "\n\n";
	}
}
