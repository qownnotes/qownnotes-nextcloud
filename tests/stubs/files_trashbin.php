<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stubs of the public interfaces of the Nextcloud "files_trashbin" app (stable32)
 */

namespace OCA\Files_Trashbin\Trash;

use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

interface ITrashBackend {
	/** @return ITrashItem[] */
	public function listTrashRoot(IUser $user): array;

	/** @return ITrashItem[] */
	public function listTrashFolder(ITrashItem $folder): array;

	public function restoreItem(ITrashItem $item);

	public function removeItem(ITrashItem $item);

	public function moveToTrash(IStorage $storage, string $internalPath): bool;

	/** @return Node|null */
	public function getTrashNodeById(IUser $user, int $fileId);
}

interface ITrashManager extends ITrashBackend {
	public function registerBackend(string $storageType, ITrashBackend $backend);

	/** @return ITrashItem[] */
	public function listTrashRoot(IUser $user): array;

	public function pauseTrash();

	public function resumeTrash();
}

interface ITrashItem extends FileInfo {
	public function getTrashBackend(): ITrashBackend;

	public function getOriginalLocation(): string;

	public function getDeletedTime(): int;

	public function getTrashPath(): string;

	public function isRootItem(): bool;

	public function getUser(): IUser;

	public function getDeletedBy(): ?IUser;

	public function getTitle(): string;
}
