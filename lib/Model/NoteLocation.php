<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Model;

/**
 * Identifies a note the way notes.sqlite does: by file name and subfolder path
 */
class NoteLocation {
	public function __construct(
		public readonly string $fileName,
		public readonly string $subFolderPath,
	) {
	}

	public static function fromNote(Note $note): self {
		return new self($note->getFileName(), $note->getSubFolderPath());
	}

	public function equals(self $other): bool {
		return $this->fileName === $other->fileName && $this->subFolderPath === $other->subFolderPath;
	}
}
