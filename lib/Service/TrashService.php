<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Trashed notes of the Nextcloud "files_trashbin" app
 */
class TrashService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IAppManager $appManager,
		private IDateTimeFormatter $dateTimeFormatter,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(?IUser $user): bool {
		return $this->appManager->isEnabledForUser('files_trashbin', $user)
			&& interface_exists(ITrashManager::class);
	}

	/**
	 * Returns trashed notes that were deleted from a folder
	 *
	 * @param string $directory folder relative to the user's files root, without leading and trailing slashes
	 * @param list<string> $extensions note file extensions without dot
	 * @param bool $recursive whether notes deleted from subfolders of the folder are included
	 * @return list<array{noteName: string, fileName: string, timestamp: int, dateString: string, data: string, originalLocation: string}>
	 */
	public function getTrashedNotes(IUser $user, string $directory, array $extensions, bool $recursive, string $sortAttribute, bool $sortDescending): array {
		$prefix = $directory === '' ? '' : $directory . '/';
		$extensions = array_map('strtolower', $extensions);

		$items = array_filter($this->listTrashRoot($user), function (ITrashItem $item) use ($prefix, $extensions, $recursive): bool {
			$location = ltrim($item->getOriginalLocation(), '/');
			$isInDirectory = $recursive
				? str_starts_with($location, $prefix)
				: str_starts_with($location, $prefix . $item->getName());

			return $item->getType() === ITrashItem::TYPE_FILE
				&& $isInDirectory
				&& in_array(strtolower(pathinfo($item->getName(), PATHINFO_EXTENSION)), $extensions, true);
		});

		usort($items, static function (ITrashItem $a, ITrashItem $b) use ($sortAttribute): int {
			return $sortAttribute === 'name'
				? strnatcasecmp($a->getName(), $b->getName())
				: $a->getDeletedTime() <=> $b->getDeletedTime();
		});
		if ($sortDescending) {
			$items = array_reverse($items);
		}

		$result = [];
		foreach ($items as $item) {
			$result[] = [
				'noteName' => pathinfo($item->getName(), PATHINFO_FILENAME),
				'fileName' => $item->getName(),
				'timestamp' => $item->getDeletedTime(),
				'dateString' => $this->dateTimeFormatter->formatDateTime($item->getDeletedTime()),
				'data' => $this->readItem($user, $item) ?? '',
				'originalLocation' => ltrim($item->getOriginalLocation(), '/'),
			];
		}

		return $result;
	}

	/**
	 * Restores a trashed note to its original location
	 *
	 * @param string $path original path of the note relative to the user's files root, or only its file name
	 * @return string|null the original location of the restored note, null if no matching note was found
	 */
	public function restore(IUser $user, string $path, int $deletedTime): ?string {
		$path = trim($path, '/');
		$name = basename($path);

		$candidates = array_filter(
			$this->listTrashRoot($user),
			static fn (ITrashItem $item): bool => $item->getName() === $name && $item->getDeletedTime() === $deletedTime,
		);
		if ($candidates === []) {
			return null;
		}

		// Prefer an exact match of the original location, if the client sent a path
		$item = null;
		foreach ($candidates as $candidate) {
			if (ltrim($candidate->getOriginalLocation(), '/') === $path) {
				$item = $candidate;
				break;
			}
		}
		$item ??= reset($candidates);

		$this->getTrashManager()->restoreItem($item);
		return ltrim($item->getOriginalLocation(), '/');
	}

	/**
	 * @return list<ITrashItem>
	 */
	private function listTrashRoot(IUser $user): array {
		if (!$this->isAvailable($user)) {
			throw new ServiceUnavailableException('The deleted files app is not enabled');
		}

		return array_values($this->getTrashManager()->listTrashRoot($user));
	}

	private function getTrashManager(): ITrashManager {
		return Server::get(ITrashManager::class);
	}

	private function readItem(IUser $user, ITrashItem $item): ?string {
		try {
			$node = $this->getTrashManager()->getTrashNodeById($user, (int)$item->getId());
			if (!$node instanceof File) {
				$node = $this->rootFolder->get('/' . $user->getUID() . '/files_trashbin/files' . $item->getTrashPath());
			}

			return $node instanceof File ? $node->getContent() : null;
		} catch (NotFoundException $e) {
			$this->logger->debug('Trashed note can not be read', ['exception' => $e]);
			return null;
		}
	}
}
