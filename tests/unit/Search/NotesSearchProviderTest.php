<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Search;

use OCA\QOwnNotes\Search\NotesSearchProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotesSearchProviderTest extends TestCase {
	public function testSplitWords(): void {
		$this->assertSame(['work', 'ünïcode'], NotesSearchProvider::splitWords("  Work \t ÜNÏCODE "));
		$this->assertSame([], NotesSearchProvider::splitWords('   '));
	}

	public static function searches(): array {
		return [
			'title' => ['meeting', true],
			'text' => ['agenda', true],
			'all words' => ['meeting agenda', true],
			'one word missing' => ['meeting lunch', false],
			'tag as word' => ['project', true],
			'tag and title' => ['work meeting', true],
			'tag only' => ['#project', true],
			'tag only, not in text' => ['#agenda', false],
			'tag path' => ['#work/project', true],
		];
	}

	#[DataProvider('searches')]
	public function testMatches(string $term, bool $expected): void {
		$this->assertSame($expected, NotesSearchProvider::matches(
			NotesSearchProvider::splitWords($term),
			'Meeting',
			"# Meeting\n\nThe Agenda",
			['Work/Project A'],
		));
	}
}
