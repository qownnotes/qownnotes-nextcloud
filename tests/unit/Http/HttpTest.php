<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Http;

use OCA\QOwnNotes\Http\ApiResponder;
use OCA\QOwnNotes\Http\ChunkCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase {
	public static function etagHeaders(): array {
		return [
			['"abc"', true],
			['abc', true],
			['W/"abc"', true],
			['"x", "abc"', true],
			['*', true],
			['"abcd"', false],
			['', false],
		];
	}

	#[DataProvider('etagHeaders')]
	public function testEtagMatches(string $header, bool $expected): void {
		$this->assertSame($expected, ApiResponder::etagMatches($header, 'abc'));
	}

	public function testChunkCursor(): void {
		$cursor = ChunkCursor::fromString('1000-500-42');
		$this->assertNotNull($cursor);
		$this->assertSame('1000-500-42', $cursor->toString());
		$this->assertTrue($cursor->isBefore(499, 100));
		$this->assertTrue($cursor->isBefore(500, 42));
		$this->assertFalse($cursor->isBefore(500, 43));
		$this->assertFalse($cursor->isBefore(501, 1));
		$this->assertNull(ChunkCursor::fromString('invalid'));
		$this->assertNull(ChunkCursor::fromString('1-2'));
	}
}
