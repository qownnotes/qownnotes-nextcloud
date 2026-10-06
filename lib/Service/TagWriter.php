<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Model\NoteLocation;
use PDO;

/**
 * Modifies the tag tables of an open notes.sqlite with the SQL semantics of QOwnNotes Desktop (Tag class) and
 * QOwnNotes Android (NoteFolderTagDatabase); no other tables are touched
 */
class TagWriter {
	public function __construct(
		private PDO $pdo,
	) {
	}

	/**
	 * @return int number of changed rows since the database was opened
	 */
	public function getTotalChanges(): int {
		return (int)$this->pdo->query('SELECT total_changes()')->fetchColumn();
	}

	/**
	 * Returns the ID of a tag by name (case-insensitive, like QOwnNotes) and parent
	 */
	public function findTag(string $name, int $parentId): ?int {
		$statement = $this->pdo->prepare('SELECT id FROM tag WHERE name = ? COLLATE NOCASE AND parent_id = ? ORDER BY id LIMIT 1');
		$statement->execute([$name, $parentId]);
		$id = $statement->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	public function tagExists(int $id): bool {
		$statement = $this->pdo->prepare('SELECT 1 FROM tag WHERE id = ?');
		$statement->execute([$id]);
		return $statement->fetchColumn() !== false;
	}

	public function createTag(string $name, int $parentId, int $priority = 0, ?string $color = null, ?string $darkColor = null): int {
		$name = self::validateName($name);
		if ($parentId !== 0 && !$this->tagExists($parentId)) {
			throw new InvalidInputException('The parent tag does not exist');
		}
		if ($this->findTag($name, $parentId) !== null) {
			throw new InvalidInputException('A tag with this name already exists');
		}

		$statement = $this->pdo->prepare('INSERT INTO tag (name, priority, parent_id, color, dark_color) VALUES (?, ?, ?, ?, ?)');
		$statement->execute([$name, $priority, $parentId, self::validateColor($color), self::validateColor($darkColor ?? $color)]);
		return (int)$this->pdo->lastInsertId();
	}

	/**
	 * Returns the IDs along a tag path, creating missing tags
	 *
	 * @param list<string> $path
	 * @return list<int>
	 */
	public function resolvePath(array $path, bool $create): array {
		$ids = [];
		$parentId = 0;
		foreach ($path as $name) {
			$name = self::validateName($name);
			$id = $this->findTag($name, $parentId);
			if ($id === null) {
				if (!$create) {
					return [];
				}
				$id = $this->createTag($name, $parentId);
			}
			$ids[] = $id;
			$parentId = $id;
		}

		return $ids;
	}

	/**
	 * @param array{name?: string, parentId?: int, priority?: int, color?: ?string, darkColor?: ?string} $changes
	 */
	public function updateTag(int $id, array $changes): void {
		$current = $this->getTag($id);
		$name = array_key_exists('name', $changes) ? self::validateName((string)$changes['name']) : $current['name'];
		$parentId = array_key_exists('parentId', $changes) ? (int)$changes['parentId'] : $current['parent_id'];

		if ($parentId !== 0 && ($parentId === $id || in_array($parentId, $this->getDescendantIds($id), true) || !$this->tagExists($parentId))) {
			throw new InvalidInputException('The parent tag is invalid');
		}
		$existing = $this->findTag($name, $parentId);
		if ($existing !== null && $existing !== $id) {
			throw new InvalidInputException('A tag with this name already exists');
		}

		$statement = $this->pdo->prepare('UPDATE tag SET name = ?, parent_id = ?, priority = ?, color = ?, dark_color = ?, updated = datetime(\'now\') WHERE id = ?');
		$statement->execute([
			$name,
			$parentId,
			array_key_exists('priority', $changes) ? (int)$changes['priority'] : $current['priority'],
			array_key_exists('color', $changes) ? self::validateColor($changes['color']) : $current['color'],
			array_key_exists('darkColor', $changes) ? self::validateColor($changes['darkColor']) : $current['dark_color'],
			$id,
		]);
	}

	/**
	 * Deletes a tag, its child tags and all their note links, like QOwnNotes Desktop
	 */
	public function deleteTag(int $id): void {
		$this->getTag($id);
		$ids = [$id, ...$this->getDescendantIds($id)];
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$this->pdo->prepare("DELETE FROM noteTagLink WHERE tag_id IN ($placeholders)")->execute($ids);
		$this->pdo->prepare("DELETE FROM tag WHERE id IN ($placeholders)")->execute($ids);
	}

	public function linkNote(int $tagId, NoteLocation $note): void {
		if (!$this->tagExists($tagId)) {
			throw new InvalidInputException('The tag does not exist');
		}

		$this->pdo->prepare('INSERT OR IGNORE INTO noteTagLink (tag_id, note_file_name, note_sub_folder_path) VALUES (?, ?, ?)')
			->execute([$tagId, $note->fileName, $note->subFolderPath]);
		$this->pdo->prepare('UPDATE noteTagLink SET stale_date = NULL WHERE tag_id = ? AND note_file_name = ? AND note_sub_folder_path = ? AND stale_date IS NOT NULL')
			->execute([$tagId, $note->fileName, $note->subFolderPath]);

		// QOwnNotes sorts tags by their most recent use and touches all ancestors of a linked tag
		$ids = [$tagId, ...$this->getAncestorIds($tagId)];
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$this->pdo->prepare("UPDATE tag SET updated = datetime('now') WHERE id IN ($placeholders)")->execute($ids);
	}

	public function unlinkNote(int $tagId, NoteLocation $note): void {
		$this->pdo->prepare('DELETE FROM noteTagLink WHERE tag_id = ? AND note_file_name = ? AND note_sub_folder_path = ?')
			->execute([$tagId, $note->fileName, $note->subFolderPath]);
	}

	/**
	 * @return list<int>
	 */
	public function getLinkedTagIds(NoteLocation $note): array {
		$statement = $this->pdo->prepare('SELECT DISTINCT tag_id FROM noteTagLink WHERE note_file_name = ? AND note_sub_folder_path = ? AND stale_date IS NULL');
		$statement->execute([$note->fileName, $note->subFolderPath]);
		return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * Moves all links of a note to its new location, merging them with existing links (like QOwnNotes Android)
	 */
	public function relinkNote(NoteLocation $from, NoteLocation $to): void {
		if ($from->equals($to)) {
			return;
		}

		$this->pdo->prepare(
			'INSERT INTO noteTagLink (tag_id, note_file_name, note_sub_folder_path, created, stale_date)
			SELECT source.tag_id, ?, ?, MIN(source.created), NULL
			FROM noteTagLink source
			WHERE source.note_file_name = ? AND source.note_sub_folder_path = ?
				AND NOT EXISTS (
					SELECT 1 FROM noteTagLink target WHERE target.tag_id = source.tag_id
						AND target.note_file_name = ? AND target.note_sub_folder_path = ?)
			GROUP BY source.tag_id'
		)->execute([$to->fileName, $to->subFolderPath, $from->fileName, $from->subFolderPath, $to->fileName, $to->subFolderPath]);

		$this->pdo->prepare('DELETE FROM noteTagLink WHERE note_file_name = ? AND note_sub_folder_path = ?')
			->execute([$from->fileName, $from->subFolderPath]);
	}

	/**
	 * Replaces the prefix of subfolder paths, like Tag::renameNoteSubFolderPathsOfLinks in QOwnNotes Desktop;
	 * only the exact path and its children are changed, not siblings with the same prefix ("work" vs. "workplace")
	 */
	public function relinkSubFolder(string $oldPath, string $newPath): void {
		if ($oldPath === $newPath || $oldPath === '') {
			return;
		}

		$this->pdo->prepare(
			'UPDATE OR IGNORE noteTagLink SET note_sub_folder_path = ? || substr(note_sub_folder_path, length(?) + 1)
			WHERE note_sub_folder_path = ? OR substr(note_sub_folder_path, 1, length(?) + 1) = ? || \'/\''
		)->execute([$newPath, $oldPath, $oldPath, $oldPath, $oldPath]);

		// Links that couldn't be moved, because the same link already exists at the new path
		$this->pdo->prepare(
			'DELETE FROM noteTagLink WHERE note_sub_folder_path = ? OR substr(note_sub_folder_path, 1, length(?) + 1) = ? || \'/\''
		)->execute([$oldPath, $oldPath, $oldPath]);
	}

	/**
	 * Marks the links of the notes in a subfolder (and its children) as stale; QOwnNotes removes stale links after
	 * 10 days and revives them if the notes come back, e.g. when they are restored from the trash
	 */
	public function markSubFolderStale(string $path): void {
		$now = self::now();
		if ($path === '') {
			$this->pdo->prepare('UPDATE noteTagLink SET stale_date = ? WHERE stale_date IS NULL')->execute([$now]);
			return;
		}

		$this->pdo->prepare(
			'UPDATE noteTagLink SET stale_date = ?
			WHERE stale_date IS NULL AND (note_sub_folder_path = ? OR substr(note_sub_folder_path, 1, length(?) + 1) = ? || \'/\')'
		)->execute([$now, $path, $path, $path]);
	}

	public function markNoteStale(NoteLocation $note): void {
		$this->pdo->prepare('UPDATE noteTagLink SET stale_date = ? WHERE stale_date IS NULL AND note_file_name = ? AND note_sub_folder_path = ?')
			->execute([self::now(), $note->fileName, $note->subFolderPath]);
	}

	public function markNoteNotStale(NoteLocation $note): void {
		$this->pdo->prepare('UPDATE noteTagLink SET stale_date = NULL WHERE stale_date IS NOT NULL AND note_file_name = ? AND note_sub_folder_path = ?')
			->execute([$note->fileName, $note->subFolderPath]);
	}

	/**
	 * @return array{id: int, name: string, parent_id: int, priority: int, color: ?string, dark_color: ?string}
	 */
	private function getTag(int $id): array {
		$statement = $this->pdo->prepare('SELECT id, name, parent_id, priority, color, dark_color FROM tag WHERE id = ?');
		$statement->execute([$id]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($row)) {
			throw new InvalidInputException('The tag does not exist');
		}

		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'parent_id' => (int)$row['parent_id'],
			'priority' => (int)$row['priority'],
			'color' => $row['color'] === null ? null : (string)$row['color'],
			'dark_color' => $row['dark_color'] === null ? null : (string)$row['dark_color'],
		];
	}

	/**
	 * @return list<int>
	 */
	private function getDescendantIds(int $id): array {
		$statement = $this->pdo->prepare(
			'WITH RECURSIVE descendants(id) AS (
				SELECT id FROM tag WHERE parent_id = ?
				UNION SELECT tag.id FROM tag JOIN descendants ON tag.parent_id = descendants.id
			) SELECT id FROM descendants'
		);
		$statement->execute([$id]);
		return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * @return list<int>
	 */
	private function getAncestorIds(int $id): array {
		$ids = [];
		$statement = $this->pdo->prepare('SELECT parent_id FROM tag WHERE id = ?');
		while (true) {
			$statement->execute([$id]);
			$parentId = (int)$statement->fetchColumn();
			if ($parentId === 0 || in_array($parentId, $ids, true)) {
				return $ids;
			}
			$ids[] = $parentId;
			$id = $parentId;
		}
	}

	/**
	 * The date format QOwnNotes Desktop stores for stale links (QDateTime in local time)
	 */
	private static function now(): string {
		return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v');
	}

	private static function validateName(string $name): string {
		$name = trim((string)preg_replace('/\s+/u', ' ', $name));
		if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
			throw new InvalidInputException('The tag name is invalid');
		}

		return $name;
	}

	private static function validateColor(mixed $color): ?string {
		if ($color === null || $color === '') {
			return null;
		}
		if (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color) !== 1) {
			throw new InvalidInputException('The tag color is invalid');
		}

		return strtolower($color);
	}
}
