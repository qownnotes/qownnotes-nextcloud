<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Exception\InvalidInputException;
use OCA\QOwnNotes\Model\Note;
use OCA\QOwnNotes\Model\NoteLocation;
use OCA\QOwnNotes\Model\TagSnapshot;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tags of the QOwnNotes note folder database "notes.sqlite"
 */
class TagService {
	public function __construct(
		private TagDatabase $database,
		private NoteFolderService $folders,
		private NoteService $notes,
		private SettingsService $settings,
		private LoggerInterface $logger,
	) {
	}

	public function getSnapshot(string $userId): TagSnapshot {
		return $this->database->read($this->folders->getNotesFolder($userId));
	}

	/**
	 * @return array{etag: ?string, schemaVersion: ?int, writable: bool, tags: list<array>}
	 */
	public function getTagTree(string $userId): array {
		$snapshot = $this->getSnapshot($userId);
		return [
			'etag' => $snapshot->etag,
			'schemaVersion' => $snapshot->schemaVersion,
			'writable' => $snapshot->writable,
			'tags' => self::buildTree($snapshot),
		];
	}

	/**
	 * All links with the IDs of the linked notes, for a full sync of clients
	 *
	 * @return array{etag: ?string, links: list<array{tagId: int, noteId: ?int, fileName: string, subFolderPath: string, stale: bool}>}
	 */
	public function getTagLinks(string $userId): array {
		$snapshot = $this->getSnapshot($userId);
		$noteIds = [];
		foreach ($this->notes->getAll($userId) as $note) {
			$noteIds[$note->getSubFolderPath() . "\0" . $note->getFileName()] = $note->getId();
		}

		$links = [];
		foreach ($snapshot->links as $link) {
			$links[] = [
				'tagId' => $link['tagId'],
				'noteId' => $noteIds[$link['subFolderPath'] . "\0" . $link['fileName']] ?? null,
				'fileName' => $link['fileName'],
				'subFolderPath' => $link['subFolderPath'],
				'stale' => $link['stale'],
			];
		}

		return ['etag' => $snapshot->etag, 'links' => $links];
	}

	/**
	 * @return array{etag: ?string, tags: list<array{id: int, name: string, path: list<string>, color: ?string, darkColor: ?string}>}
	 */
	public function getNoteTags(string $userId, Note $note): array {
		return self::serializeNoteTags($this->getSnapshot($userId), NoteLocation::fromNote($note));
	}

	/**
	 * Sets the tags of a note to exactly the given tags; tag paths are created if they don't exist
	 *
	 * @param list<int>|null $tagIds
	 * @param list<list<string>>|null $tagPaths
	 */
	public function setNoteTags(string $userId, Note $note, ?array $tagIds, ?array $tagPaths, ?string $ifMatch): array {
		$location = NoteLocation::fromNote($note);
		$snapshot = $this->modify($userId, static function (TagWriter $writer) use ($location, $tagIds, $tagPaths): void {
			$wanted = array_map('intval', $tagIds ?? []);
			foreach ($tagPaths ?? [] as $path) {
				$ids = $writer->resolvePath(self::validatePath($path), true);
				$wanted[] = $ids[array_key_last($ids)];
			}
			$wanted = array_values(array_unique($wanted));

			$current = $writer->getLinkedTagIds($location);
			foreach (array_diff($current, $wanted) as $tagId) {
				$writer->unlinkNote($tagId, $location);
			}
			foreach (array_diff($wanted, $current) as $tagId) {
				$writer->linkNote($tagId, $location);
			}
		}, $ifMatch);

		return self::serializeNoteTags($snapshot, $location);
	}

	/**
	 * @param array{name?: mixed, parentId?: mixed, priority?: mixed, color?: mixed, darkColor?: mixed} $data
	 * @return array{etag: ?string, tag: array}
	 */
	public function createTag(string $userId, array $data, ?string $ifMatch): array {
		$id = 0;
		$snapshot = $this->modify($userId, static function (TagWriter $writer) use ($data, &$id): void {
			$id = $writer->createTag(
				(string)($data['name'] ?? ''),
				(int)($data['parentId'] ?? 0),
				(int)($data['priority'] ?? 0),
				isset($data['color']) ? (string)$data['color'] : null,
				isset($data['darkColor']) ? (string)$data['darkColor'] : null,
			);
		}, $ifMatch);

		return ['etag' => $snapshot->etag, 'tag' => self::serializeTag($snapshot, $id)];
	}

	/**
	 * @param array<string, mixed> $changes name, parentId, priority, color, darkColor
	 * @return array{etag: ?string, tag: array}
	 */
	public function updateTag(string $userId, int $id, array $changes, ?string $ifMatch): array {
		$changes = array_intersect_key($changes, array_flip(['name', 'parentId', 'priority', 'color', 'darkColor']));
		$snapshot = $this->modify($userId, static function (TagWriter $writer) use ($id, $changes): void {
			$writer->updateTag($id, $changes);
		}, $ifMatch);

		return ['etag' => $snapshot->etag, 'tag' => self::serializeTag($snapshot, $id)];
	}

	/**
	 * Deletes a tag with its child tags and links
	 */
	public function deleteTag(string $userId, int $id, ?string $ifMatch): array {
		$snapshot = $this->modify($userId, static function (TagWriter $writer) use ($id): void {
			$writer->deleteTag($id);
		}, $ifMatch);

		return ['etag' => $snapshot->etag];
	}

	/**
	 * Applies several operations in one transaction and with a single upload of notes.sqlite
	 *
	 * Operations: {op: "link"|"unlink", noteId, tagId|tagPath}, {op: "create", name, parentId?, color?, darkColor?, priority?},
	 * {op: "update", id, ...changes}, {op: "delete", id}
	 *
	 * @param list<array<string, mixed>> $operations
	 */
	public function batch(string $userId, array $operations, ?string $ifMatch): array {
		// Resolve notes before the transaction, so it stays short
		$locations = [];
		foreach ($operations as $operation) {
			if (!is_array($operation)) {
				throw new InvalidInputException('Invalid operation');
			}
			if (isset($operation['noteId'])) {
				$noteId = (int)$operation['noteId'];
				$locations[$noteId] ??= NoteLocation::fromNote($this->notes->get($userId, $noteId));
			}
		}

		$snapshot = $this->modify($userId, static function (TagWriter $writer) use ($operations, $locations): void {
			foreach ($operations as $operation) {
				$op = (string)($operation['op'] ?? '');
				$location = isset($operation['noteId']) ? $locations[(int)$operation['noteId']] : null;
				$tagId = isset($operation['tagPath'])
					? (static function () use ($writer, $operation, $op): int {
						$ids = $writer->resolvePath(self::validatePath($operation['tagPath']), $op === 'link');
						if ($ids === []) {
							throw new InvalidInputException('The tag does not exist');
						}
						return $ids[array_key_last($ids)];
					})()
					: (int)($operation['tagId'] ?? $operation['id'] ?? 0);

				match ($op) {
					'link' => $location !== null ? $writer->linkNote($tagId, $location) : throw new InvalidInputException('noteId is missing'),
					'unlink' => $location !== null ? $writer->unlinkNote($tagId, $location) : throw new InvalidInputException('noteId is missing'),
					'create' => $writer->createTag(
						(string)($operation['name'] ?? ''),
						(int)($operation['parentId'] ?? 0),
						(int)($operation['priority'] ?? 0),
						isset($operation['color']) ? (string)$operation['color'] : null,
						isset($operation['darkColor']) ? (string)$operation['darkColor'] : null,
					),
					'update' => $writer->updateTag($tagId, array_intersect_key($operation, array_flip(['name', 'parentId', 'priority', 'color', 'darkColor']))),
					'delete' => $writer->deleteTag($tagId),
					default => throw new InvalidInputException('Unknown operation "' . $op . '"'),
				};
			}
		}, $ifMatch);

		return [
			'etag' => $snapshot->etag,
			'schemaVersion' => $snapshot->schemaVersion,
			'writable' => $snapshot->writable,
			'tags' => self::buildTree($snapshot),
		];
	}

	/**
	 * Moves the tag links of a renamed or moved note; returns false if the links couldn't be updated
	 */
	public function relinkNote(string $userId, NoteLocation $from, NoteLocation $to): bool {
		if ($from->equals($to)) {
			return true;
		}

		return $this->modifyIfExists($userId, static fn (TagWriter $writer) => $writer->relinkNote($from, $to));
	}

	public function relinkSubFolder(string $userId, string $oldPath, string $newPath): bool {
		return $this->modifyIfExists($userId, static fn (TagWriter $writer) => $writer->relinkSubFolder($oldPath, $newPath));
	}

	public function markSubFolderStale(string $userId, string $path): bool {
		return $this->modifyIfExists($userId, static fn (TagWriter $writer) => $writer->markSubFolderStale($path));
	}

	public function markNoteStale(string $userId, NoteLocation $location): bool {
		return $this->modifyIfExists($userId, static fn (TagWriter $writer) => $writer->markNoteStale($location));
	}

	public function markNoteNotStale(string $userId, NoteLocation $location): bool {
		return $this->modifyIfExists($userId, static fn (TagWriter $writer) => $writer->markNoteNotStale($location));
	}

	/**
	 * @param callable(TagWriter): void $modify
	 */
	private function modify(string $userId, callable $modify, ?string $ifMatch): TagSnapshot {
		return $this->database->modify(
			$this->folders->getNotesFolder($userId),
			$modify,
			$ifMatch,
			$this->settings->isCreateTagDatabase($userId),
		);
	}

	/**
	 * Implicit changes (e.g. after renames) are only made if notes.sqlite exists, and never fail the request
	 *
	 * @param callable(TagWriter): void $modify
	 */
	private function modifyIfExists(string $userId, callable $modify): bool {
		if (!TagDatabase::isAvailable()) {
			return false;
		}

		try {
			$notesFolder = $this->folders->getNotesFolder($userId);
			if (!$notesFolder->nodeExists(NoteFolderService::TAG_DATABASE_FILE_NAME)) {
				return true;
			}

			$this->database->modify($notesFolder, $modify, null, false);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Tags in notes.sqlite could not be updated', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * @return list<string>
	 */
	private static function validatePath(mixed $path): array {
		if (is_string($path)) {
			$path = explode('/', $path);
		}
		if (!is_array($path) || $path === []) {
			throw new InvalidInputException('The tag path is invalid');
		}

		return array_values(array_map('strval', $path));
	}

	/**
	 * @return list<array>
	 */
	private static function buildTree(TagSnapshot $snapshot): array {
		$noteCounts = [];
		foreach ($snapshot->links as $link) {
			if (!$link['stale']) {
				$noteCounts[$link['tagId']] = ($noteCounts[$link['tagId']] ?? 0) + 1;
			}
		}

		$children = [];
		foreach ($snapshot->tags as $tag) {
			// Tags with a missing parent are shown at the top level
			$parentId = isset($snapshot->tags[$tag['parentId']]) ? $tag['parentId'] : 0;
			$children[$parentId][] = $tag['id'];
		}

		$build = static function (int $parentId, array $seen) use (&$build, $snapshot, $children, $noteCounts): array {
			$nodes = [];
			foreach ($children[$parentId] ?? [] as $id) {
				if (isset($seen[$id])) {
					continue;
				}
				$tag = $snapshot->tags[$id];
				$nodes[] = [
					'id' => $id,
					'name' => $tag['name'],
					'parentId' => $tag['parentId'],
					'priority' => $tag['priority'],
					'color' => $tag['color'],
					'darkColor' => $tag['darkColor'],
					'noteCount' => $noteCounts[$id] ?? 0,
					'children' => $build($id, $seen + [$id => true]),
				];
			}
			return $nodes;
		};

		return $build(0, []);
	}

	private static function serializeTag(TagSnapshot $snapshot, int $id): array {
		$tag = $snapshot->tags[$id] ?? null;
		if ($tag === null) {
			return [];
		}

		return [
			'id' => $id,
			'name' => $tag['name'],
			'parentId' => $tag['parentId'],
			'priority' => $tag['priority'],
			'color' => $tag['color'],
			'darkColor' => $tag['darkColor'],
			'path' => $snapshot->getPath($id),
		];
	}

	private static function serializeNoteTags(TagSnapshot $snapshot, NoteLocation $location): array {
		$tags = [];
		foreach ($snapshot->getTagIdsForNote($location) as $id) {
			$tag = $snapshot->tags[$id];
			$tags[] = [
				'id' => $id,
				'name' => $tag['name'],
				'path' => $snapshot->getPath($id),
				'color' => $tag['color'],
				'darkColor' => $tag['darkColor'],
			];
		}

		return ['etag' => $snapshot->etag, 'tags' => $tags];
	}
}
