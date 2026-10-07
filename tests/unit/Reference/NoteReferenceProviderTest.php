<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Reference;

use OCA\QOwnNotes\Reference\NoteReferenceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoteReferenceProviderTest extends TestCase {
	public static function links(): array {
		return [
			['https://cloud.example.com/', 'https://cloud.example.com/index.php/apps/qownnotes/note/42', 42],
			['https://cloud.example.com/', 'https://cloud.example.com/apps/qownnotes/note/42?folder=Work', 42],
			['https://cloud.example.com/nc/', 'https://cloud.example.com/nc/index.php/apps/qownnotes/note/7', 7],
			['https://cloud.example.com/nc/', 'https://cloud.example.com/index.php/apps/qownnotes/note/7', null],
			['https://cloud.example.com/', 'https://other.example.com/index.php/apps/qownnotes/note/42', null],
			['https://cloud.example.com/', 'https://cloud.example.com/index.php/apps/notes/note/42', null],
			['https://cloud.example.com/', 'https://cloud.example.com/index.php/apps/qownnotes/note/42x', null],
			['https://cloud.example.com/', 'https://cloud.example.com/index.php/apps/qownnotes/folder/Work', null],
		];
	}

	#[DataProvider('links')]
	public function testParseNoteId(string $serverUrl, string $link, ?int $expected): void {
		$this->assertSame($expected, NoteReferenceProvider::parseNoteId($serverUrl, $link));
	}
}
