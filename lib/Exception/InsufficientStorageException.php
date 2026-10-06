<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Exception;

class InsufficientStorageException extends QOwnNotesException {
	public function __construct(string $message = 'There is not enough free storage space', ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
}
