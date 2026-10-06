<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Model\NoteLocation;
use OCA\QOwnNotes\Service\TagDatabase;
use OCA\QOwnNotes\Service\TagWriter;
use PDO;
use PHPUnit\Framework\TestCase;

class TagWriterTest extends TestCase {
	private PDO $pdo;
	private TagWriter $writer;

	protected function setUp(): void {
		$this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		foreach (TagDatabase::CREATE_SCHEMA as $statement) {
			$this->pdo->exec($statement);
		}
		$this->writer = new TagWriter($this->pdo);
	}

	/**
	 * @return list<array{string, string, string, bool}> tag name, file name, subfolder path, stale
	 */
	private function links(): array {
		$rows = $this->pdo->query('SELECT t.name, l.note_file_name, l.note_sub_folder_path, l.stale_date FROM noteTagLink l JOIN tag t ON t.id = l.tag_id ORDER BY t.name, l.note_sub_folder_path, l.note_file_name')->fetchAll(PDO::FETCH_NUM);
		return array_map(static fn (array $row): array => [$row[0], $row[1], $row[2], $row[3] !== null], $rows);
	}

	public function testCreateAndFindTagsCaseInsensitively(): void {
		$work = $this->writer->createTag('Work', 0, 0, '#FF0000');
		$this->assertSame($work, $this->writer->findTag('work', 0));
		$this->assertNull($this->writer->findTag('Work', 99));
		$this->assertSame(['#ff0000', '#ff0000'], $this->pdo->query("SELECT color, dark_color FROM tag WHERE id = $work")->fetch(PDO::FETCH_NUM));

		$this->expectException(InvalidInputException::class);
		$this->writer->createTag('WORK', 0);
	}

	public function testResolvePathCreatesHierarchy(): void {
		$ids = $this->writer->resolvePath(['Work', 'Project A'], true);
		$this->assertCount(2, $ids);
		$this->assertSame($ids, $this->writer->resolvePath(['work', 'project a'], false));
		$this->assertSame([], $this->writer->resolvePath(['Work', 'Missing'], false));
		$this->assertSame($ids[0], (int)$this->pdo->query("SELECT parent_id FROM tag WHERE id = {$ids[1]}")->fetchColumn());
	}

	public function testUpdateTagPreventsCycles(): void {
		[$parent, $child] = $this->writer->resolvePath(['Parent', 'Child'], true);
		$this->writer->updateTag($child, ['name' => 'Renamed', 'parentId' => 0, 'color' => '#00ff00']);
		$this->assertSame(['Renamed', 0, '#00ff00'], $this->pdo->query("SELECT name, parent_id, color FROM tag WHERE id = $child")->fetch(PDO::FETCH_NUM));

		$this->writer->updateTag($child, ['parentId' => $parent]);
		$this->expectException(InvalidInputException::class);
		$this->writer->updateTag($parent, ['parentId' => $child]);
	}

	public function testDeleteTagRemovesChildrenAndLinks(): void {
		[$parent, $child] = $this->writer->resolvePath(['Parent', 'Child'], true);
		$other = $this->writer->createTag('Other', 0);
		$note = new NoteLocation('Note.md', '');
		$this->writer->linkNote($child, $note);
		$this->writer->linkNote($other, $note);

		$this->writer->deleteTag($parent);

		$this->assertSame([['Other', 'Note.md', '', false]], $this->links());
		$this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM tag')->fetchColumn());
	}

	public function testLinkAndUnlinkNotes(): void {
		[$parent, $child] = $this->writer->resolvePath(['Parent', 'Child'], true);
		$this->pdo->exec("UPDATE tag SET updated = '2000-01-01 00:00:00'");
		$note = new NoteLocation('Note.md', 'Work');

		$this->writer->linkNote($child, $note);
		$this->writer->linkNote($child, $note);
		$this->assertSame([['Child', 'Note.md', 'Work', false]], $this->links());
		$this->assertSame([$child], $this->writer->getLinkedTagIds($note));
		// Linking touches the tag and its ancestors
		$this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM tag WHERE updated = '2000-01-01 00:00:00'")->fetchColumn());

		$this->writer->unlinkNote($child, $note);
		$this->assertSame([], $this->links());
	}

	public function testLinkingRevivesStaleLinks(): void {
		$tag = $this->writer->createTag('Tag', 0);
		$note = new NoteLocation('Note.md', '');
		$this->writer->linkNote($tag, $note);
		$this->writer->markNoteStale($note);
		$this->assertSame([['Tag', 'Note.md', '', true]], $this->links());
		$this->assertSame([], $this->writer->getLinkedTagIds($note));

		$this->writer->linkNote($tag, $note);
		$this->assertSame([['Tag', 'Note.md', '', false]], $this->links());
	}

	public function testRelinkNoteMergesLinks(): void {
		$a = $this->writer->createTag('A', 0);
		$b = $this->writer->createTag('B', 0);
		$from = new NoteLocation('Old.md', 'Work');
		$to = new NoteLocation('New.md', 'Archive');
		$this->writer->linkNote($a, $from);
		$this->writer->linkNote($b, $from);
		$this->writer->linkNote($a, $to);

		$this->writer->relinkNote($from, $to);

		$this->assertSame([['A', 'New.md', 'Archive', false], ['B', 'New.md', 'Archive', false]], $this->links());
	}

	public function testRelinkSubFolderOnlyChangesPrefix(): void {
		$tag = $this->writer->createTag('Tag', 0);
		foreach (['work', 'work/sub', 'work/work', 'workplace', 'other/work'] as $path) {
			$this->writer->linkNote($tag, new NoteLocation('Note.md', $path));
		}

		$this->writer->relinkSubFolder('work', 'archive/job');

		$paths = array_column($this->links(), 2);
		sort($paths);
		$this->assertSame(['archive/job', 'archive/job/sub', 'archive/job/work', 'other/work', 'workplace'], $paths);
	}

	public function testRelinkSubFolderMergesExistingLinks(): void {
		$tag = $this->writer->createTag('Tag', 0);
		$this->writer->linkNote($tag, new NoteLocation('Note.md', 'a'));
		$this->writer->linkNote($tag, new NoteLocation('Note.md', 'b'));

		$this->writer->relinkSubFolder('a', 'b');

		$this->assertSame([['Tag', 'Note.md', 'b', false]], $this->links());
	}

	public function testMarkSubFolderStale(): void {
		$tag = $this->writer->createTag('Tag', 0);
		foreach (['work', 'work/sub', 'workplace'] as $path) {
			$this->writer->linkNote($tag, new NoteLocation('Note.md', $path));
		}

		$this->writer->markSubFolderStale('work');

		$stale = array_column(array_filter($this->links(), static fn (array $link): bool => $link[3]), 2);
		sort($stale);
		$this->assertSame(['work', 'work/sub'], $stale);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}$/', (string)$this->pdo->query('SELECT stale_date FROM noteTagLink WHERE stale_date IS NOT NULL LIMIT 1')->fetchColumn());
	}

	public function testInvalidInput(): void {
		$this->expectException(InvalidInputException::class);
		$this->writer->createTag('Tag', 0, 0, 'red');
	}
}
