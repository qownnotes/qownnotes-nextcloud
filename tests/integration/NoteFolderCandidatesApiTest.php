<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * Other folders with notes (QOwnNotes extension of the Notes API settings), see docs/api/notes-api.md
 */
class NoteFolderCandidatesApiTest extends ApiTestCase {
	public function testCandidates(): void {
		[$settings] = self::api('GET', 'settings');
		$this->assertSame('Notes', $settings['notesPath']);
		[$candidates] = self::api('GET', 'settings/note-folder-candidates');
		$this->assertSame([], $candidates);

		self::davMkcol('Notes');
		self::davPut('Notes/Current.md', '# Current');
		// A localized folder of the Nextcloud Notes app and a QOwnNotes Desktop note folder
		self::davMkcol('Notizen');
		self::davPut('Notizen/A.md', '# A');
		self::davMkcol('Notizen/Work');
		self::davPut('Notizen/Work/B.txt', 'B');
		self::davMkcol('Archive');
		self::davMkcol('Archive/Desktop');
		self::davPut('Archive/Desktop/notes.sqlite', 'not a real database');
		self::davPut('Archive/Desktop/C.md', '# C');
		// Ignored: no notes, and hidden folders
		self::davMkcol('Notas');
		self::davPut('Notas/photo.jpg', 'jpg');
		self::davMkcol('Notas/.hidden');
		self::davPut('Notas/.hidden/D.md', '# D');

		[$candidates, $response] = self::api('GET', 'settings/note-folder-candidates');
		$this->assertSame('1.4', $response->getHeaderLine('X-Notes-API-Versions'));
		$this->assertSame([
			[
				'path' => 'Archive/Desktop',
				'noteCount' => 1,
				'noteCountCapped' => false,
				'hasTagDatabase' => true,
				'sources' => ['tagDatabase'],
			],
			[
				'path' => 'Notizen',
				'noteCount' => 2,
				'noteCountCapped' => false,
				'hasTagDatabase' => false,
				'sources' => ['defaultName'],
			],
		], $candidates);

		// The settings response is unchanged
		[$settings] = self::api('GET', 'settings');
		$this->assertArrayNotHasKey('candidates', $settings);

		// The current note folder, its subfolders and its ancestors are never candidates
		self::api('PUT', 'settings', ['notesPath' => 'Archive/Desktop']);
		[$candidates] = self::api('GET', 'settings/note-folder-candidates');
		$this->assertSame(['Notizen', 'Notes'], array_column($candidates, 'path'));

		self::api('PUT', 'settings', ['notesPath' => 'Notes']);
	}
}
