<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Search;

use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Model\TagSnapshot;
use OCA\QOwnNotes\Search\NotesSearchProvider;
use OCA\QOwnNotes\Service\AppSettings;
use OCA\QOwnNotes\Service\NoteService;
use OCA\QOwnNotes\Service\TagService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NotesSearchProviderTest extends TestCase {
	public function testTagSearchUsesSuffixFreeClientIdentity(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Meeting.markdown');
		$file->method('getContent')->willReturn('Agenda');
		$notes = $this->createMock(NoteService::class);
		$notes->method('getAll')->willReturn([new Note($file, 'Work', false)]);
		$tags = $this->createMock(TagService::class);
		$tags->method('getSnapshot')->willReturn(new TagSnapshot('etag', 16, true, [
			1 => ['id' => 1, 'name' => 'Project', 'parentId' => 0, 'priority' => 0, 'color' => null, 'darkColor' => null, 'updated' => null],
		], [
			['id' => 1, 'tagId' => 1, 'fileName' => 'Meeting', 'subFolderPath' => 'Work', 'stale' => false],
		]));
		$settings = $this->createMock(AppSettings::class);
		$settings->method('isUiEnabled')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('#project');
		$query->method('getLimit')->willReturn(10);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$provider = new NotesSearchProvider($l10n, $this->createMock(IURLGenerator::class), $settings, $notes, $tags, new NullLogger());
		$result = $provider->search($user, $query)->jsonSerialize();
		$this->assertCount(1, $result['entries']);
		$this->assertStringContainsString('#Project', json_encode($result['entries'], JSON_THROW_ON_ERROR));
	}

	public function testSplitWords(): void {
		$this->assertSame(['work', 'ünïcode'], NotesSearchProvider::splitWords("  Work \t ÜNÏCODE "));
		$this->assertSame([], NotesSearchProvider::splitWords('   '));
	}

	public static function searches(): array {
		return [
			'title' => ['meeting', true],
			'text' => ['agenda', true],
			'all words' => ['meeting agenda', true],
			'one word missing' => ['meeting lunch', false],
			'tag as word' => ['project', true],
			'tag and title' => ['work meeting', true],
			'tag only' => ['#project', true],
			'tag only, not in text' => ['#agenda', false],
			'tag path' => ['#work/project', true],
		];
	}

	#[DataProvider('searches')]
	public function testMatches(string $term, bool $expected): void {
		$this->assertSame($expected, NotesSearchProvider::matches(
			NotesSearchProvider::splitWords($term),
			'Meeting',
			"# Meeting\n\nThe Agenda",
			['Work/Project A'],
		));
	}
}
