<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Service\AttachmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase {
	public static function links(): array {
		return [
			['', 'media/image.png', 'media/image.png'],
			['', './media/image.png', 'media/image.png'],
			['Work', '../media/image.png', 'media/image.png'],
			['Work/Project', '../../media/a%20b.png', 'media/a b.png'],
			['Work', 'local.png', 'Work/local.png'],
			['Work', '/media/image.png', 'media/image.png'],
			['Work', 'file://../media/image.png', 'media/image.png'],
			['Work', '../media/image.png?raw=1#top', 'media/image.png'],
			['', '../outside.png', null],
			['Work', '../../outside.png', null],
			['Work', '..\\..\\outside.png', null],
		];
	}

	#[DataProvider('links')]
	public function testResolveRelativePath(string $subFolderPath, string $link, ?string $expected): void {
		$this->assertSame($expected, AttachmentService::resolveRelativePath($subFolderPath, $link));
	}

	public function testGetRelativeLink(): void {
		$this->assertSame('media/a.png', AttachmentService::getRelativeLink('', 'media/a.png'));
		$this->assertSame('../media/a.png', AttachmentService::getRelativeLink('Work', 'media/a.png'));
		$this->assertSame('../../attachments/a.pdf', AttachmentService::getRelativeLink('Work/Project', 'attachments/a.pdf'));
	}
}
