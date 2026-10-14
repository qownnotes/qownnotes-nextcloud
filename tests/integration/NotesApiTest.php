<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * The Notes API v1.4 as used by QOwnNotes Android, see docs/api/notes-api.md
 */
class NotesApiTest extends ApiTestCase {
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	public function testSettings(): void {
		[$settings] = self::api('GET', 'settings');
		$this->assertSame('Notes', $settings['notesPath']);
		$this->assertSame('.md', $settings['fileSuffix']);
		$this->assertSame('^\.', $settings['ignoreNoteSubFolders']);
		$this->assertTrue($settings['subfoldersEnabled']);
		$this->assertFalse($settings['hideNavigation']);

		// Paths are normalized, the folder is created and unknown keys are ignored
		[$settings] = self::api('PUT', 'settings', ['notesPath' => '/Other/../Text Notes/', 'fileSuffix' => 'TXT', 'unknown' => 1]);
		$this->assertSame('Text Notes', $settings['notesPath']);
		$this->assertSame('.txt', $settings['fileSuffix']);
		$this->assertTrue(self::davExists('Text Notes'));

		[$note] = self::api('POST', 'notes', ['title' => 'Text note', 'content' => 'text']);
		$this->assertSame('/Text Notes/Text note.txt', $note['internalPath']);
		$this->assertSame('Text Notes', self::ocs('GET', 'cloud/capabilities')['capabilities']['qownnotes']['notes_path']);

		[$settings] = self::api('PUT', 'settings', ['notesPath' => 'Notes', 'fileSuffix' => '.md']);
		$this->assertSame('Notes', $settings['notesPath']);
	}

	public function testCreateReadUpdateDelete(): void {
		[$note, $response] = self::api('POST', 'notes', ['title' => 'First note', 'content' => "# First note\n\nHello"]);
		$this->assertSame('First note', $note['title']);
		$this->assertSame('', $note['category']);
		$this->assertFalse($note['favorite']);
		$this->assertFalse($note['readonly']);
		$this->assertSame('/Notes/First note.md', $note['internalPath']);
		$this->assertNotEmpty($note['etag']);
		$this->assertSame("# First note\n\nHello", self::davGet('Notes/First note.md'));

		// Same title: numbered like in QOwnNotes Desktop, compared case-insensitively
		[$duplicate] = self::api('POST', 'notes', ['title' => 'first note', 'content' => 'dup']);
		$this->assertSame('first note 1', $duplicate['title']);

		// Without title, the title is taken from the first line; invalid characters are removed
		[$derived] = self::api('POST', 'notes', ['content' => "# Derived: title\n\nText"]);
		$this->assertSame('Derived title', $derived['title']);

		// Categories are subfolders, created on demand
		[$nested] = self::api('POST', 'notes', ['title' => 'Nested', 'category' => 'Work/Project', 'favorite' => true, 'modified' => 1700000000]);
		$this->assertSame('Work/Project', $nested['category']);
		$this->assertTrue($nested['favorite']);
		$this->assertSame(1700000000, $nested['modified']);
		$this->assertTrue(self::davExists('Notes/Work/Project/Nested.md'));

		// Internal and ignored folders can't be categories
		self::api('POST', 'notes', ['title' => 'x', 'category' => 'media'], expect: 400);
		self::api('POST', 'notes', ['title' => 'x', 'category' => 'Work/.hidden'], expect: 400);
		// ".." is resolved, so categories can't leave the note folder
		[$inside] = self::api('POST', 'notes', ['title' => 'Inside', 'category' => '../../Outside']);
		$this->assertSame('/Notes/Outside/Inside.md', $inside['internalPath']);
		self::api('DELETE', "notes/{$inside['id']}");

		[$read, $response] = self::api('GET', "notes/{$note['id']}");
		$this->assertSame("# First note\n\nHello", $read['content']);
		$this->assertSame('"' . $note['etag'] . '"', $response->getHeaderLine('ETag'));
		self::api('GET', "notes/{$note['id']}", headers: ['If-None-Match' => '"' . $note['etag'] . '"'], expect: 304);
		self::api('GET', 'notes/999999999', expect: 404);

		// Lost updates are prevented with If-Match, the current note is sent back
		[$current] = self::api('PUT', "notes/{$note['id']}", ['content' => 'conflict'], ['If-Match' => '"outdated"'], 412);
		$this->assertSame("# First note\n\nHello", $current['content']);
		[$updated] = self::api('PUT', "notes/{$note['id']}", ['content' => "# First note\n\nUpdated"], ['If-Match' => '"' . $note['etag'] . '"']);
		$this->assertNotSame($note['etag'], $updated['etag']);

		// Renaming and moving keeps the ID
		[$renamed] = self::api('PUT', "notes/{$note['id']}", ['title' => 'Renamed: note', 'category' => 'Work']);
		$this->assertSame($note['id'], $renamed['id']);
		$this->assertSame('Renamed note', $renamed['title']);
		$this->assertSame('/Notes/Work/Renamed note.md', $renamed['internalPath']);
		$this->assertTrue(self::davExists('Notes/Work/Renamed note.md'));
		$this->assertFalse(self::davExists('Notes/First note.md'));

		// The server never renames a note because its first line changed
		[$changed] = self::api('PUT', "notes/{$note['id']}", ['content' => "# Other heading\n"]);
		$this->assertSame('Renamed note', $changed['title']);

		self::api('DELETE', "notes/{$duplicate['id']}");
		self::api('GET', "notes/{$duplicate['id']}", expect: 404);
		$this->assertFalse(self::davExists('Notes/first note 1.md'));
	}

	public function testListing(): void {
		self::davMkcol('Notes/Listing');
		self::davMkcol('Notes/.hidden');
		self::davMkcol('Notes/media');
		self::davPut('Notes/Listing/From WebDAV.md', 'webdav');
		self::davPut('Notes/Listing/Text file.txt', 'text');
		self::davPut('Notes/Listing/Conflict (conflicted copy 2026-10-14 120000).md', 'conflict');
		self::davPut('Notes/.hidden/Hidden.md', 'hidden');
		self::davPut('Notes/media/Media.md', 'media');
		self::davPut('Notes/image.png', 'no note');
		self::davPut('Notes/notes.json', 'no note');

		[$notes, $response] = self::api('GET', 'notes');
		$titles = array_map(static fn (array $note): string => $note['category'] . '/' . $note['title'], $notes);
		$this->assertContains('Listing/From WebDAV', $titles);
		$this->assertContains('Listing/Text file', $titles);
		$this->assertContains('Listing/Conflict (conflicted copy 2026-10-14 120000)', $titles);
		foreach (['Hidden', 'Media', 'image', 'notes'] as $title) {
			$this->assertNotContains($title, array_column($notes, 'title'));
		}
		$this->assertNotEmpty($response->getHeaderLine('ETag'));
		$this->assertNotEmpty($response->getHeaderLine('Last-Modified'));
		self::api('GET', 'notes', headers: ['If-None-Match' => $response->getHeaderLine('ETag')], expect: 304);

		[$byCategory] = self::api('GET', 'notes?category=Listing');
		$this->assertCount(3, $byCategory);

		[$excluded] = self::api('GET', 'notes?exclude=content,etag,internalPath');
		foreach ($excluded as $note) {
			$this->assertArrayNotHasKey('content', $note);
			$this->assertArrayNotHasKey('etag', $note);
			$this->assertArrayNotHasKey('internalPath', $note);
			$this->assertArrayHasKey('title', $note);
		}

		// Notes that didn't change since pruneBefore only have their ID
		[$pruned] = self::api('GET', 'notes?pruneBefore=4000000000');
		foreach ($pruned as $note) {
			$this->assertSame(['id'], array_keys($note));
		}
		$this->assertEqualsCanonicalizing(array_column($notes, 'id'), array_column($pruned, 'id'));

		// The chunked listing sends every note once in full; the last chunk also has the IDs of all notes
		$seen = [];
		$cursor = null;
		for ($i = 0; $i <= count($notes); $i++) {
			[$chunk, $response] = self::api('GET', 'notes?chunkSize=2' . ($cursor === null ? '' : '&chunkCursor=' . rawurlencode($cursor)));
			$full = array_filter($chunk, static fn (array $note): bool => isset($note['title']));
			$seen = array_merge($seen, array_column($full, 'id'));
			$cursor = $response->getHeaderLine('X-Notes-Chunk-Cursor');
			if ($cursor === '') {
				$this->assertEqualsCanonicalizing(array_column($notes, 'id'), array_column($chunk, 'id'));
				break;
			}
			$this->assertCount(2, $full);
			$this->assertGreaterThan(0, (int)$response->getHeaderLine('X-Notes-Chunk-Pending'));
		}
		$this->assertEqualsCanonicalizing(array_column($notes, 'id'), $seen);

		self::api('GET', 'notes?chunkCursor=invalid', expect: 400);
	}

	public function testAttachments(): void {
		[$note] = self::api('POST', 'notes', ['title' => 'With image', 'category' => 'Attach/Deep']);

		$upload = static fn (string $name, string $content, string $apiVersion = 'v1') => self::request('POST', "/index.php/apps/qownnotes/api/$apiVersion/attachment/{$note['id']}", [
			'multipart' => [['name' => 'file', 'contents' => $content, 'filename' => $name]],
		]);

		$response = $upload('image.png', base64_decode(self::PNG), 'v1.4');
		$this->assertSame(200, $response->getStatusCode(), (string)$response->getBody());
		$this->assertSame('../../media/image.png', json_decode((string)$response->getBody(), true)['filename']);
		$this->assertTrue(self::davExists('Notes/media/image.png'));

		// Names that are taken get a number
		$response = $upload('image.png', base64_decode(self::PNG));
		$this->assertSame('../../media/image-1.png', json_decode((string)$response->getBody(), true)['filename']);

		$response = $upload('doc.pdf', '%PDF');
		$this->assertSame('../../attachments/doc.pdf', json_decode((string)$response->getBody(), true)['filename']);

		$attachment = static fn (string $method, string $path, string $apiVersion = 'v1') => self::request($method, "/index.php/apps/qownnotes/api/$apiVersion/attachment/{$note['id']}?path=" . rawurlencode($path));

		$response = $attachment('GET', '../../media/image.png', 'v1.4');
		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringStartsWith('image/png', $response->getHeaderLine('Content-Type'));
		$this->assertSame(base64_decode(self::PNG), (string)$response->getBody());

		// Paths outside of the note folder are never served
		$this->assertSame(404, $attachment('GET', '../../../../../etc/passwd')->getStatusCode());
		$this->assertSame(404, $attachment('GET', '../../../../Outside.md')->getStatusCode());

		// Only media files and attachments can be deleted
		$this->assertSame(403, $attachment('DELETE', 'With image.md')->getStatusCode());
		$this->assertSame(200, $attachment('DELETE', '../../media/image.png')->getStatusCode());
		$this->assertFalse(self::davExists('Notes/media/image.png'));
	}
}
