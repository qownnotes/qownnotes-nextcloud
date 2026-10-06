<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Attribute;

use Attribute;

/**
 * Marks a controller that is only available if the web interface is enabled (not in API-only mode)
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RequiresUi {
}
