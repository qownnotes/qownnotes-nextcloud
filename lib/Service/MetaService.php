<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\QOwnNotes\Db\Meta;
use OCA\QOwnNotes\Db\MetaMapper;
use OCA\QOwnNotes\Model\Note;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Maintains the note ETags and last update times; the cache is validated lazily against the file ETags,
 * so no file event listeners are needed and changes from any client (WebDAV, desktop sync) are detected
 */
class MetaService {
	public function __construct(
		private MetaMapper $mapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Returns the up-to-date metadata of all given notes, and removes metadata of notes that don't exist anymore
	 *
	 * @param array<int, Note> $notes keyed by note ID
	 * @return array<int, Meta> keyed by note ID
	 */
	public function getAll(string $userId, array $notes): array {
		$metas = [];
		foreach ($this->mapper->findAllByUser($userId) as $meta) {
			if (!isset($notes[$meta->getFileId()])) {
				$this->mapper->delete($meta);
				continue;
			}
			$metas[$meta->getFileId()] = $meta;
		}

		$result = [];
		foreach ($notes as $id => $note) {
			$result[$id] = isset($metas[$id]) ? $this->updateIfNeeded($metas[$id], $note) : $this->create($userId, $note);
		}

		return $result;
	}

	public function get(string $userId, Note $note): Meta {
		try {
			return $this->updateIfNeeded($this->mapper->findByFileId($userId, $note->getId()), $note);
		} catch (DoesNotExistException) {
			return $this->create($userId, $note);
		}
	}

	public function deleteByUser(string $userId): void {
		$this->mapper->deleteByUser($userId);
	}

	private function create(string $userId, Note $note): Meta {
		$meta = new Meta();
		$meta->setUserId($userId);
		$meta->setFileId($note->getId());
		$this->refresh($meta, $note);

		try {
			$this->mapper->insert($meta);
		} catch (DbException $e) {
			// A concurrent request probably inserted the same note, which leads to the same result
			$this->logger->debug('Could not insert note metadata', ['exception' => $e]);
		}

		return $meta;
	}

	private function updateIfNeeded(Meta $meta, Note $note): Meta {
		$this->refresh($meta, $note);
		if ($meta->getUpdatedFields() !== []) {
			$this->mapper->update($meta);
		}

		return $meta;
	}

	private function refresh(Meta $meta, Note $note): void {
		// The content is only read if the file changed, because reading it is expensive
		if ($meta->getFileEtag() !== $note->getFileEtag() || $meta->getContentEtag() === '') {
			$meta->setFileEtag($note->getFileEtag());
			$contentEtag = md5($note->getContent());
			if ($contentEtag !== $meta->getContentEtag()) {
				$meta->setContentEtag($contentEtag);
			}
		}

		$etag = md5(json_encode([
			$note->getId(),
			$note->getFile()->getPath(),
			$note->getTitle(),
			$note->getSubFolderPath(),
			$note->getModified(),
			$note->isFavorite(),
			$note->isReadonly(),
			$meta->getContentEtag(),
		], JSON_THROW_ON_ERROR));

		if ($etag !== $meta->getEtag()) {
			$meta->setEtag($etag);
			$meta->setLastUpdate($this->timeFactory->getTime());
		}
	}
}
