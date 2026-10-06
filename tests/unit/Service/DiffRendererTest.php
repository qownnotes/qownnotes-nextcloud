<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\DiffRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiffRendererTest extends TestCase {
	public static function diffs(): array {
		return [
			'identical' => ['same text', 'same text', 'same text'],
			'word changed' => ['The quick fox', 'The slow fox', 'The <del>quick</del><ins>slow</ins> fox'],
			'word added' => ['a c', 'a b c', 'a <ins>b </ins>c'],
			'word removed' => ['a b c', 'a c', 'a <del>b </del>c'],
			'empty from' => ['', 'new', '<ins>new</ins>'],
			'empty to' => ['old', '', '<del>old</del>'],
			'escaping' => ['<b>x</b>', '<b>y</b>', '&lt;b&gt;<del>x</del><ins>y</ins>&lt;/b&gt;'],
			'newlines kept' => ["line 1\nline 2\n", "line 1\nline two\n", "line 1\nline <del>2</del><ins>two</ins>\n"],
			'unicode' => ['Grüße 📝', 'Grüße ✅', 'Grüße <del>📝</del><ins>✅</ins>'],
		];
	}

	#[DataProvider('diffs')]
	public function testRender(string $from, string $to, string $expected): void {
		$this->assertSame($expected, (new DiffRenderer())->render($from, $to));
	}

	public function testRenderReconstructsBothTexts(): void {
		$from = "# Title\n\nSome text with several words.\n\n- item 1\n- item 2\n";
		$to = "# New title\n\nSome other text with words.\n\n- item 1\n- item 3\n- item 4\n";
		$html = (new DiffRenderer())->render($from, $to);

		$this->assertSame($from, html_entity_decode((string)preg_replace(['/<ins>.*?<\/ins>/s', '/<\/?del>/'], '', $html)));
		$this->assertSame($to, html_entity_decode((string)preg_replace(['/<del>.*?<\/del>/s', '/<\/?ins>/'], '', $html)));
	}

	public function testLargeDifferencesFallBack(): void {
		$from = implode(' ', range(1, 3000));
		$to = implode(' ', array_reverse(range(1, 3000)));
		$html = (new DiffRenderer())->render($from, $to);

		$this->assertSame($from, html_entity_decode((string)preg_replace(['/<ins>.*?<\/ins>/s', '/<\/?del>/'], '', $html)));
		$this->assertSame($to, html_entity_decode((string)preg_replace(['/<del>.*?<\/del>/s', '/<\/?ins>/'], '', $html)));
	}
}
