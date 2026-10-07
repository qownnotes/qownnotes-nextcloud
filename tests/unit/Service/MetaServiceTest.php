<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Db\Meta;
use OCA\QOwnNotes\Db\MetaMapper;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Service\MetaService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetaServiceTest extends TestCase {
	public static function movedPaths(): array {
		return [
			['/alice/files/Notes/Meeting.txt'],
			['/alice/files/Archive/Notes/Meeting.md'],
		];
	}

	#[DataProvider('movedPaths')]
	public function testPathChangesInvalidateEtagEvenWhenOtherAttributesStayTheSame(string $newPath): void {
		$path = '/alice/files/Notes/Meeting.md';
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(123);
		$file->method('getName')->willReturnCallback(static function () use (&$path): string {
			return basename($path);
		});
		$file->method('getPath')->willReturnCallback(static function () use (&$path): string {
			return $path;
		});
		$file->method('getMTime')->willReturn(1700000000);
		$file->method('getEtag')->willReturn('unchanged-file-etag');
		$file->method('getContent')->willReturn('# Meeting');
		$file->method('isUpdateable')->willReturn(true);
		$note = new Note($file, '', false);
		$meta = new Meta();
		$mapper = $this->createMock(MetaMapper::class);
		$mapper->method('findByFileId')->with('alice', 123)->willReturn($meta);
		$mapper->expects($this->exactly(2))->method('update')->with($meta)->willReturn($meta);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnOnConsecutiveCalls(100, 200);
		$service = new MetaService($mapper, $time, $this->createMock(LoggerInterface::class));

		$etag = $service->get('alice', $note)->getEtag();
		$meta->resetUpdatedFields();
		$this->assertSame($etag, $service->get('alice', $note)->getEtag());
		$path = $newPath;
		$this->assertNotSame($etag, $service->get('alice', $note)->getEtag());
		$this->assertSame(200, $meta->getLastUpdate());
	}
}
