<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\integration;

/**
 * QOwnNotes API 1.1: subfolders and the tags of notes.sqlite, see docs/api/qownnotes-api.md
 */
class SubFoldersAndTagsApiTest extends ApiTestCase {
	private static function child(array $node, string $name): array {
		$children = array_column($node['children'], null, 'name');
		self::assertArrayHasKey($name, $children, 'Subfolders: ' . implode(', ', array_keys($children)));
		return $children[$name];
	}

	/**
	 * @return list<string> the tag links in notes.sqlite as "tag name|file name|subfolder|stale"
	 */
	private static function tagLinks(): array {
		[$tags] = self::api('GET', 'tags');
		$names = [];
		$collect = static function (array $tags) use (&$collect, &$names): void {
			foreach ($tags as $tag) {
				$names[$tag['id']] = $tag['name'];
				$collect($tag['children']);
			}
		};
		$collect($tags['tags']);

		[$links] = self::api('GET', 'tag-links');
		$result = array_map(
			static fn (array $link): string => implode('|', [$names[$link['tagId']] ?? '?', $link['fileName'], $link['subFolderPath'], $link['stale'] ? '1' : '0']),
			$links['links'],
		);
		sort($result);
		return $result;
	}

	public function testSubFolders(): void {
		self::api('POST', 'notes', ['title' => 'Root note']);
		self::api('POST', 'notes', ['title' => 'Work note', 'category' => 'Work']);
		self::api('POST', 'notes', ['title' => 'Project note', 'category' => 'Work/Project']);
		self::davMkcol('Notes/.git');
		self::davMkcol('Notes/media');

		[$tree] = self::api('GET', 'subfolders');
		$this->assertSame('', $tree['path']);
		$this->assertSame('', $tree['name']);
		$this->assertSame(1, $tree['noteCount']);
		$this->assertSame(3, $tree['noteCountRecursive']);
		$this->assertSame(['Work'], array_column($tree['children'], 'name'), 'Ignored folders are not listed');
		$work = self::child($tree, 'Work');
		$this->assertSame(1, $work['noteCount']);
		$this->assertSame(2, $work['noteCountRecursive']);
		$this->assertSame('Work/Project', self::child($work, 'Project')['path']);

		// Empty folders are part of the tree
		[$created] = self::api('POST', 'subfolders', ['path' => 'Empty/Deep']);
		$this->assertSame('Empty/Deep', $created['path']);
		[$tree] = self::api('GET', 'subfolders');
		$this->assertSame(0, self::child($tree, 'Empty')['noteCountRecursive']);

		self::api('POST', 'subfolders', ['path' => 'media'], expect: 400);
		self::api('POST', 'subfolders', ['path' => 'Work/.hidden'], expect: 400);
		self::api('POST', 'subfolders', ['path' => 'Empty'], expect: 400);
		self::api('PATCH', 'subfolders', ['path' => 'Work', 'newPath' => 'Work/Inside'], expect: 400);
		self::api('PATCH', 'subfolders', ['path' => 'Missing', 'newPath' => 'Other'], expect: 404);
		self::api('DELETE', 'subfolders?path=', expect: 400);
		// ".." is resolved inside the note folder, so "../Notes" is the missing subfolder "Notes/Notes"
		self::api('DELETE', 'subfolders?path=' . rawurlencode('../Notes'), expect: 404);
		$this->assertTrue(self::davExists('Notes/Work'));

		[$moved] = self::api('PATCH', 'subfolders', ['path' => 'Empty/Deep', 'newPath' => 'Deeper']);
		$this->assertSame('Deeper', $moved['folder']['path']);
		$this->assertTrue(self::davExists('Notes/Deeper'));

		self::api('DELETE', 'subfolders?path=Deeper');
		self::api('DELETE', 'subfolders?path=Empty');
		$this->assertFalse(self::davExists('Notes/Empty'));

		// Settings: with subfolders off, only the notes of the root are listed
		self::api('PUT', 'settings', ['subfoldersEnabled' => false]);
		try {
			[$notes] = self::api('GET', 'notes');
			$this->assertSame(['Root note'], array_column($notes, 'title'));
		} finally {
			self::api('PUT', 'settings', ['subfoldersEnabled' => true]);
		}
	}

	public function testTags(): void {
		[$tags] = self::api('GET', 'tags');
		$this->assertNull($tags['etag'], 'notes.sqlite does not exist yet');
		$this->assertSame([], $tags['tags']);

		[$meeting] = self::api('POST', 'notes', ['title' => 'Meeting', 'category' => 'Team/Weekly']);
		[$plan] = self::api('POST', 'notes', ['title' => 'Plan', 'category' => 'Team']);

		// The first change creates notes.sqlite with the schema of QOwnNotes Desktop
		[$noteTags] = self::api('PUT', "note/{$meeting['id']}/tags", ['tagPaths' => [['Work', 'Project A'], 'Important']]);
		$paths = array_map(static fn (array $tag): string => implode('/', $tag['path']), $noteTags['tags']);
		$this->assertEqualsCanonicalizing(['Important', 'Work/Project A'], $paths);
		$etag = $noteTags['etag'];
		$this->assertNotEmpty($etag);
		$this->assertStringStartsWith("SQLite format 3\0", self::davGet('Notes/notes.sqlite'));
		$this->assertSame(['Important|Meeting|Team/Weekly|0', 'Project A|Meeting|Team/Weekly|0'], self::tagLinks());

		[$tags, $response] = self::api('GET', 'tags');
		$this->assertSame($etag, $tags['etag']);
		$this->assertSame(16, $tags['schemaVersion']);
		$this->assertTrue($tags['writable']);
		$top = array_column($tags['tags'], null, 'name');
		$this->assertSame(1, $top['Important']['noteCount']);
		$this->assertSame('Project A', $top['Work']['children'][0]['name']);
		self::api('GET', 'tags', headers: ['If-None-Match' => $response->getHeaderLine('ETag')], expect: 304);

		// Changes with an outdated ETag fail, names are unique per parent (case-insensitive)
		self::api('POST', 'tags', ['name' => 'Archive'], ['If-Match' => '"outdated"'], 412);
		[$created, $response] = self::api('POST', 'tags', ['name' => 'Archive', 'color' => '#FF8800'], ['If-Match' => "\"$etag\""]);
		$this->assertSame('#ff8800', $created['tag']['color']);
		$this->assertSame('#ff8800', $created['tag']['darkColor']);
		$this->assertNotSame($etag, $created['etag']);
		$this->assertSame('"' . $created['etag'] . '"', $response->getHeaderLine('ETag'));
		self::api('POST', 'tags', ['name' => 'archive'], expect: 400);
		self::api('POST', 'tags', ['name' => 'Bad color', 'color' => 'red'], expect: 400);

		[$updated] = self::api('PATCH', "tags/{$created['tag']['id']}", ['name' => 'Old stuff', 'parentId' => $top['Work']['id']]);
		$this->assertSame(['Work', 'Old stuff'], $updated['tag']['path']);
		self::api('PATCH', "tags/{$top['Work']['id']}", ['parentId' => $created['tag']['id']], expect: 400);
		self::api('PATCH', 'tags/999999', ['name' => 'x'], expect: 400);

		// A batch is one transaction: all operations or none
		[$batch] = self::api('POST', 'tags/batch', ['operations' => [
			['op' => 'link', 'noteId' => $plan['id'], 'tagId' => $top['Important']['id']],
			['op' => 'link', 'noteId' => $plan['id'], 'tagPath' => ['Work', 'Old stuff']],
			['op' => 'unlink', 'noteId' => $meeting['id'], 'tagId' => $top['Important']['id']],
		]]);
		$this->assertNotEmpty($batch['etag']);
		$expected = ['Important|Plan|Team|0', 'Old stuff|Plan|Team|0', 'Project A|Meeting|Team/Weekly|0'];
		$this->assertSame($expected, self::tagLinks());
		self::api('POST', 'tags/batch', ['operations' => [
			['op' => 'link', 'noteId' => $meeting['id'], 'tagId' => $top['Important']['id']],
			['op' => 'explode'],
		]], expect: 400);
		$this->assertSame($expected, self::tagLinks(), 'A failed batch must not change anything');

		[$links] = self::api('GET', 'tag-links');
		$byFile = array_column($links['links'], 'noteId', 'fileName');
		$this->assertSame($meeting['id'], $byFile['Meeting']);
		$this->assertSame($plan['id'], $byFile['Plan']);

		// Moving a subfolder moves the tag links of its notes
		[$moved] = self::api('PATCH', 'subfolders', ['path' => 'Team/Weekly', 'newPath' => 'Archive/Weekly']);
		$this->assertTrue($moved['tagsRelinked']);
		$this->assertContains('Project A|Meeting|Archive/Weekly|0', self::tagLinks());

		// The Notes API only relinks the tags of renamed notes with the relink header
		self::api('PUT', "notes/{$meeting['id']}", ['title' => 'Meeting renamed'], ['X-QOwnNotes-Relink-Tags' => '1']);
		$this->assertContains('Project A|Meeting renamed|Archive/Weekly|0', self::tagLinks());
		self::api('PUT', "notes/{$meeting['id']}", ['title' => 'Meeting']);
		$this->assertContains('Project A|Meeting renamed|Archive/Weekly|0', self::tagLinks());
		self::api('PUT', "notes/{$meeting['id']}", ['title' => 'Meeting renamed']);

		// With the relink header, relative media links follow the new subfolder depth
		[$media] = self::api('POST', 'notes', ['title' => 'Media links', 'content' => '![i](media/x.png) [a](attachments/a.pdf)']);
		[$movedNote] = self::api('PUT', "notes/{$media['id']}", ['category' => 'Archive/Media'], ['X-QOwnNotes-Relink-Tags' => '1']);
		$this->assertSame('![i](../../media/x.png) [a](../../attachments/a.pdf)', $movedNote['content']);

		// Deleting a subfolder marks the links of its notes stale
		[$deleted] = self::api('DELETE', 'subfolders?path=Archive');
		$this->assertTrue($deleted['tagsUpdated']);
		$this->assertContains('Project A|Meeting renamed|Archive/Weekly|1', self::tagLinks());

		// Deleting a tag deletes its child tags and links
		[$tags] = self::api('GET', 'tags');
		$work = array_column($tags['tags'], null, 'name')['Work'];
		[$result] = self::api('DELETE', "tags/{$work['id']}", headers: ['If-Match' => '"' . $tags['etag'] . '"']);
		$this->assertNotSame($tags['etag'], $result['etag']);
		$this->assertSame(['Important|Plan|Team|0'], self::tagLinks());
		[$tags] = self::api('GET', 'tags');
		$this->assertSame(['Important'], array_column($tags['tags'], 'name'));
	}

	public function testTagsOfMissingNotes(): void {
		self::api('GET', 'note/999999999/tags', expect: 404);
		self::api('PUT', 'note/999999999/tags', ['tagPaths' => ['x']], expect: 404);
	}
}
