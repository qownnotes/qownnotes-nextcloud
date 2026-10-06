<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Exception;

/**
 * A feature can't be used on this server, e.g. tags without the PHP extension pdo_sqlite
 */
class ServiceUnavailableException extends QOwnNotesException {
}
