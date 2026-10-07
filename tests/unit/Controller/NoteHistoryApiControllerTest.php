<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Controller;

use OCA\QOwnNotes\Controller\NoteHistoryApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoteHistoryApiControllerTest extends TestCase {
	public static function locations(): array {
		return [
			['Notes', 'Notes/a.md', ''],
			['Notes', '/Notes/Work/Project/a.md', 'Work/Project'],
			['Notes', 'NotesOther/a.md', null],
			['Notes', 'Other/a.md', null],
			['Folder/Notes', 'Folder/Notes/Work/a.md', 'Work'],
			['', 'Work/a.md', 'Work'],
			['', 'a.md', ''],
		];
	}

	#[DataProvider('locations')]
	public function testGetSubFolderPath(string $notesPath, string $location, ?string $expected): void {
		$this->assertSame($expected, NoteHistoryApiController::getSubFolderPath($notesPath, $location));
	}
}
