<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return ['routes' => [
	// Web interface (disabled in API-only mode)
	['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	['name' => 'page#index', 'url' => '/note/{id}', 'verb' => 'GET', 'postfix' => 'note', 'requirements' => ['id' => '\d+']],
	['name' => 'page#index', 'url' => '/folder/{path}', 'verb' => 'GET', 'postfix' => 'folder', 'requirements' => ['path' => '.+']],

	// Admin settings
	['name' => 'admin_settings#save', 'url' => '/admin/settings', 'verb' => 'POST'],
]];
