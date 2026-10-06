<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

/**
 * Access to the QOwnNotes note folder database "notes.sqlite"
 */
class TagDatabase {
	/** QOwnNotes Desktop note folder schema versions whose tag tables can be modified */
	public const WRITABLE_SCHEMA_VERSIONS = [15, 16];

	public static function isAvailable(): bool {
		return extension_loaded('pdo_sqlite');
	}
}
