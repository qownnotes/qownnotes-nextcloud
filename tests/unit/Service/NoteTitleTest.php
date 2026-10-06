<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\NoteTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoteTitleTest extends TestCase {
	public static function titles(): array {
		return [
			['Meeting notes', 'Meeting notes'],
			['a/b\\c:d', 'abcd'],
			['What? <Really> "yes" | * no', 'What Really yes no'],
			["  multiple \t spaces  ", 'multiple spaces'],
			['...hidden', 'hidden'],
			['trailing dot.', 'trailing dot'],
			['Ünïcödé 📝', 'Ünïcödé 📝'],
			['', ''],
			[str_repeat('a', 300), str_repeat('a', 200)],
		];
	}

	#[DataProvider('titles')]
	public function testSanitize(string $title, string $expected): void {
		$this->assertSame($expected, NoteTitle::sanitize($title));
	}

	public static function contents(): array {
		return [
			["# My note\n\nText", 'My note'],
			["My note\n=======\n\nText", 'My note'],
			["#NoSpace\nText", '#NoSpace'],
			["## Second level\nText", '## Second level'],
			["\nText", 'Note'],
			['', 'Note'],
			["# Path/With:Colon\r\nText", 'PathWithColon'],
		];
	}

	#[DataProvider('contents')]
	public function testFromContent(string $content, string $expected): void {
		$this->assertSame($expected, NoteTitle::fromContent($content));
	}

	public function testCreateHeader(): void {
		$this->assertSame("# Title\n\n", NoteTitle::createHeader(' Title '));
		$this->assertSame("Title\n=====\n\n", NoteTitle::createHeader('Title', 'setext'));
		$this->assertSame(40, strlen(explode("\n", NoteTitle::createHeader(str_repeat('x', 50), 'setext'))[1]));
	}
}
