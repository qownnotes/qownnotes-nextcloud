<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Exception;

use Exception;

/**
 * Base class of all exceptions of this app; their messages are safe to show to the user
 */
abstract class QOwnNotesException extends Exception {
}
