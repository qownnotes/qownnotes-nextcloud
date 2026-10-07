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
	 * A short plain text excerpt of the note text without its headline, e.g. for search results and the dashboard
	 */
	public static function excerpt(string $content, int $maxLength = 100): string {
		$lines = preg_split('/\R/u', $content) ?: [];
		// The first line is the headline, an underline (setext headline) belongs to it
		array_shift($lines);
		if (isset($lines[0]) && preg_match('/^\s*(=+|-+)\s*$/u', $lines[0]) === 1) {
			array_shift($lines);
		}

		$text = '';
		foreach ($lines as $line) {
			$line = trim((string)preg_replace('/^\s*(#+\s+|[-*+]\s+(\[[ xX]\]\s+)?|>\s*|\d+[.)]\s+)/u', '', $line));
			if ($line === '' || preg_match('/^(`{3,}|~{3,}|[-*_=\s]{3,})$/u', $line) === 1) {
				continue;
			}
			$text .= ($text === '' ? '' : ' ') . $line;
			if (mb_strlen($text, 'UTF-8') > $maxLength) {
				break;
			}
		}

		// Images are left out, links keep their text, emphasis and code markers are removed
		$text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text);
		$text = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
		$text = (string)preg_replace('/(\*\*|__|~~|`)/u', '', $text);
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));
		return mb_strlen($text, 'UTF-8') > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1, 'UTF-8')) . '…' : $text;
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
