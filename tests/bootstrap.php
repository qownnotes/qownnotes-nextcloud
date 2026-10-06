<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';

// The public Nextcloud API (OCP) is provided by the nextcloud/ocp package, but it is not autoloadable via composer
spl_autoload_register(static function (string $class): void {
	foreach (['OCP\\' => __DIR__ . '/../vendor/nextcloud/ocp/OCP/', 'NCU\\' => __DIR__ . '/../vendor/nextcloud/ocp/NCU/'] as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});

require_once __DIR__ . '/stubs/oc.php';
require_once __DIR__ . '/stubs/files_versions.php';
require_once __DIR__ . '/stubs/files_trashbin.php';
