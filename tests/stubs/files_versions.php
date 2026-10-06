<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stubs of the public interfaces of the Nextcloud "files_versions" app (stable32)
 */

namespace OCA\Files_Versions\Versions;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

interface IVersionBackend {
	public function useBackendForStorage(IStorage $storage): bool;

	/** @return IVersion[] */
	public function getVersionsForFile(IUser $user, FileInfo $file): array;

	public function createVersion(IUser $user, FileInfo $file);

	public function rollback(IVersion $version);

	/** @return resource|false */
	public function read(IVersion $version);

	/** @param int|string $revision */
	public function getVersionFile(IUser $user, FileInfo $sourceFile, $revision): File;

	public function getRevision(Node $node): int;
}

interface IVersionManager extends IVersionBackend {
	public function registerBackend(string $storageType, IVersionBackend $backend);

	public function getBackendForStorage(IStorage $storage): IVersionBackend;
}

interface IVersion {
	public function getBackend(): IVersionBackend;

	public function getSourceFile(): FileInfo;

	/** @return int|string */
	public function getRevisionId();

	public function getTimestamp(): int;

	public function getSize(): int|float;

	public function getSourceFileName(): string;

	public function getMimeType(): string;

	public function getVersionPath(): string;

	public function getUser(): IUser;
}
