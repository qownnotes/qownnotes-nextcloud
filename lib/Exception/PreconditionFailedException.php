<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Exception;

/**
 * An If-Match precondition failed; the current state of the resource is sent back to the client
 */
class PreconditionFailedException extends QOwnNotesException {
	public function __construct(
		private array $currentData = [],
		string $message = 'The resource was changed in the meantime',
	) {
		parent::__construct($message);
	}

	public function getCurrentData(): array {
		return $this->currentData;
	}
}
