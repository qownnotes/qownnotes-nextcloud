<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Model;

/**
 * The tag data of a notes.sqlite file at one point in time
 *
 * @psalm-type TagRow = array{id: int, name: string, priority: int, parentId: int, color: ?string, darkColor: ?string, updated: ?string}
 * @psalm-type LinkRow = array{id: int, tagId: int, fileName: string, subFolderPath: string, stale: bool}
 */
class TagSnapshot {
	/**
	 * @param string|null $etag file ETag of notes.sqlite, null if the file doesn't exist
	 * @param int|null $schemaVersion "database_version" of the file, null if the file doesn't exist
	 * @param array<int, TagRow> $tags keyed by tag ID
	 * @param list<LinkRow> $links
	 */
	public function __construct(
		public readonly ?string $etag,
		public readonly ?int $schemaVersion,
		public readonly bool $writable,
		public readonly array $tags,
		public readonly array $links,
	) {
	}

	public function exists(): bool {
		return $this->etag !== null;
	}

	/**
	 * @return list<string> names from the top-level tag down to the tag
	 */
	public function getPath(int $tagId): array {
		$path = [];
		$seen = [];
		while (isset($this->tags[$tagId]) && !isset($seen[$tagId])) {
			$seen[$tagId] = true;
			array_unshift($path, $this->tags[$tagId]['name']);
			$tagId = $this->tags[$tagId]['parentId'];
		}

		return $path;
	}

	/**
	 * @return list<int> IDs of the non-stale tags linked to a note
	 */
	public function getTagIdsForNote(NoteLocation $location): array {
		$ids = [];
		foreach ($this->links as $link) {
			if (!$link['stale'] && $link['fileName'] === $location->fileName && $link['subFolderPath'] === $location->subFolderPath
				&& isset($this->tags[$link['tagId']])) {
				$ids[] = $link['tagId'];
			}
		}

		return array_values(array_unique($ids));
	}

	public function toArray(): array {
		return [
			'etag' => $this->etag,
			'schemaVersion' => $this->schemaVersion,
			'writable' => $this->writable,
			'tags' => $this->tags,
			'links' => $this->links,
		];
	}

	public static function fromArray(array $data): self {
		return new self($data['etag'], $data['schemaVersion'], $data['writable'], $data['tags'], $data['links']);
	}
}
