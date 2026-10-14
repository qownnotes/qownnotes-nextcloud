<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * QOwnNotes API 1.1: note details, versions and the trash by note ID, see docs/api/qownnotes-api.md
 */
class NoteHistoryApiTest extends ApiTestCase {
	public function testInfoVersionsTrashAndRestore(): void {
		[$note] = self::api('POST', 'notes', ['title' => 'History', 'category' => 'Hist', 'content' => "# History\n\nfirst"]);
		self::nextSecond();
		self::api('PUT', "notes/{$note['id']}", ['content' => "# History\n\nsecond"]);

		[$info] = self::api('GET', "note/{$note['id']}/info");
		$this->assertSame($note['id'], $info['id']);
		$this->assertSame('History.md', $info['fileName']);
		$this->assertSame('Hist', $info['subFolderPath']);
		$this->assertSame('Notes/Hist/History.md', $info['path']);
		$this->assertSame(strlen("# History\n\nsecond"), $info['size']);
		$this->assertFalse($info['readonly']);
		self::api('GET', 'note/999999999/info', expect: 404);

		if ($info['versionsAvailable']) {
			[$versions] = self::api('GET', "note/{$note['id']}/versions");
			$this->assertSame($note['id'], $versions['id']);
			$this->assertSame(["# History\n\nfirst"], array_column($versions['versions'], 'data'));
			$this->assertStringContainsString('<del>second</del><ins>first</ins>', $versions['versions'][0]['diffHtml']);
		}

		if (!$info['trashAvailable']) {
			$this->markTestSkipped('The deleted files app is disabled');
		}

		// Notes outside of the note folder are not part of its trash
		self::davPut('Outside.md', 'outside');
		self::dav('DELETE', 'Outside.md');

		self::api('PUT', "note/{$note['id']}/tags", ['tagPaths' => ['History tag']]);
		self::api('DELETE', "notes/{$note['id']}", headers: ['X-QOwnNotes-Relink-Tags' => '1']);
		[$links] = self::api('GET', 'tag-links');
		$this->assertSame([[null, true]], array_map(static fn (array $link): array => [$link['noteId'], $link['stale']], $links['links']));

		[$trash] = self::api('GET', 'trash');
		$this->assertSame(['Notes/Hist/History.md'], array_column($trash['notes'], 'originalLocation'));
		$trashed = $trash['notes'][0];
		$this->assertSame('History', $trashed['title']);
		$this->assertSame('History.md', $trashed['fileName']);
		$this->assertSame('Hist', $trashed['subFolderPath']);
		$this->assertSame("# History\n\nsecond", $trashed['content']);

		[$withoutContent] = self::api('GET', 'trash?content=0');
		$this->assertSame(['Notes/Hist/History.md'], array_column($withoutContent['notes'], 'originalLocation'));
		$this->assertNull($withoutContent['notes'][0]['content']);

		self::api('POST', 'trash/restore', ['originalLocation' => 'Outside.md', 'deleted' => $trashed['deleted']], expect: 400);
		self::api('POST', 'trash/restore', ['originalLocation' => 'Notes/Hist/Other.md', 'deleted' => $trashed['deleted']], expect: 404);

		// With the relink header, the restored note gets its tags back
		[$restored] = self::api('POST', 'trash/restore', ['originalLocation' => $trashed['originalLocation'], 'deleted' => $trashed['deleted']], ['X-QOwnNotes-Relink-Tags' => '1']);
		$this->assertIsInt($restored['id']);
		[$noteTags] = self::api('GET', "note/{$restored['id']}/tags");
		$this->assertSame([['History tag']], array_column($noteTags['tags'], 'path'));
		self::api('POST', 'trash/restore', ['originalLocation' => $trashed['originalLocation'], 'deleted' => $trashed['deleted']], expect: 404);
	}
}
