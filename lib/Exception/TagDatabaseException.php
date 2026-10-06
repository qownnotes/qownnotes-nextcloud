<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Exception;

/**
 * notes.sqlite is damaged, uses an unsupported format or schema, or can't be modified
 */
class TagDatabaseException extends ServiceUnavailableException {
}
