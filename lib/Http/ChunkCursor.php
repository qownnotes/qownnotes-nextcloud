<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Http;

/**
 * Position in a chunked note list of the Notes API: notes are sorted by (last update, ID), and the cursor
 * points after the last sent note; the start time of the first request becomes the Last-Modified time
 */
class ChunkCursor {
	public function __construct(
		public readonly int $timeStart,
		public readonly int $noteLastUpdate,
		public readonly int $noteId,
	) {
	}

	public static function fromString(string $cursor): ?self {
		if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $cursor, $matches) !== 1) {
			return null;
		}

		return new self((int)$matches[1], (int)$matches[2], (int)$matches[3]);
	}

	public function toString(): string {
		return $this->timeStart . '-' . $this->noteLastUpdate . '-' . $this->noteId;
	}

	/**
	 * Whether a note was already sent in a previous chunk
	 */
	public function isBefore(int $lastUpdate, int $noteId): bool {
		return $lastUpdate < $this->noteLastUpdate
			|| ($lastUpdate === $this->noteLastUpdate && $noteId <= $this->noteId);
	}
}
