<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCP\ITagManager;
use OCP\ITags;

/**
 * Favorites are the Nextcloud file favorites, so they are shared with the Files and Notes apps
 */
class FavoriteService {
	/** @var array<string, ITags|null> */
	private array $tags = [];

	public function __construct(
		private ITagManager $tagManager,
	) {
	}

	/**
	 * @return array<int, true> file IDs of all favorites of the user
	 */
	public function getFavoriteIds(string $userId): array {
		$tags = $this->getTags($userId);
		if ($tags === null) {
			return [];
		}

		$ids = $tags->getFavorites();
		return is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : [];
	}

	public function setFavorite(string $userId, int $fileId, bool $favorite): void {
		$tags = $this->getTags($userId);
		if ($tags === null) {
			return;
		}

		if ($favorite) {
			$tags->addToFavorites($fileId);
		} else {
			$tags->removeFromFavorites($fileId);
		}
	}

	private function getTags(string $userId): ?ITags {
		if (!array_key_exists($userId, $this->tags)) {
			$this->tags[$userId] = $this->tagManager->load('files', [], false, $userId);
		}

		return $this->tags[$userId];
	}
}
