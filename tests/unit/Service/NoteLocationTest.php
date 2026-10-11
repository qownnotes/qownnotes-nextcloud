<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Model\NoteLocation;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class NoteLocationTest extends TestCase {
	public function testTagIdentityUsesDesktopBaseNameRatherThanDiskFilename(): void {
		foreach (['Meeting.md' => 'Meeting', 'Meeting.txt' => 'Meeting', 'Report.final.qnote' => 'Report.final'] as $fileName => $baseName) {
			$file = $this->createMock(File::class);
			$file->method('getName')->willReturn($fileName);
			$location = NoteLocation::fromNote(new Note($file, 'Work/Notes', false));
			$this->assertSame($baseName, $location->fileName);
			$this->assertSame('Work/Notes', $location->subFolderPath);
		}
	}
}
