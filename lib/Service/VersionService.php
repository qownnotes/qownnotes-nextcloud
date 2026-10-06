<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\QOwnNotes\Exception\NoteNotFoundException;
use OCA\QOwnNotes\Exception\ServiceUnavailableException;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\Server;

/**
 * Note versions of the Nextcloud "files_versions" app
 */
class VersionService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IAppManager $appManager,
		private IDateTimeFormatter $dateTimeFormatter,
		private DiffRenderer $diffRenderer,
	) {
	}

	public function isAvailable(?IUser $user): bool {
		return $this->appManager->isEnabledForUser('files_versions', $user)
			&& interface_exists(IVersionManager::class);
	}

	/**
	 * Returns the previous versions of a file, newest first; the current version is not included
	 *
	 * @param string $path path relative to the user's files root
	 * @return list<array{timestamp: int, humanReadableTimestamp: string, diffHtml: string, data: string}>
	 */
	public function getVersions(IUser $user, string $path): array {
		if (!$this->isAvailable($user)) {
			throw new ServiceUnavailableException('The versions app is not enabled');
		}

		try {
			$file = $this->rootFolder->getUserFolder($user->getUID())->get($path);
		} catch (NotFoundException $e) {
			throw new NoteNotFoundException('Requested file was not found!', $e);
		}
		if (!$file instanceof File) {
			throw new NoteNotFoundException('Requested file was not found!');
		}

		/** @var IVersionManager $versionManager */
		$versionManager = Server::get(IVersionManager::class);
		$currentContent = $file->getContent();
		$currentRevision = (string)$file->getMTime();

		$versions = array_filter(
			$versionManager->getVersionsForFile($user, $file),
			static fn (IVersion $version): bool => (string)$version->getRevisionId() !== $currentRevision,
		);
		usort($versions, static fn (IVersion $a, IVersion $b): int => $b->getTimestamp() <=> $a->getTimestamp());

		$result = [];
		foreach ($versions as $version) {
			$data = $this->readVersion($versionManager, $version);
			if ($data === null) {
				continue;
			}

			$result[] = [
				'timestamp' => $version->getTimestamp(),
				'humanReadableTimestamp' => $this->dateTimeFormatter->formatTimeSpan($version->getTimestamp()),
				'diffHtml' => $this->diffRenderer->render($currentContent, $data),
				'data' => $data,
			];
		}

		return $result;
	}

	private function readVersion(IVersionManager $versionManager, IVersion $version): ?string {
		$handle = $versionManager->read($version);
		if (!is_resource($handle)) {
			return null;
		}

		try {
			$data = stream_get_contents($handle);
			return $data === false ? null : $data;
		} finally {
			fclose($handle);
		}
	}
}
