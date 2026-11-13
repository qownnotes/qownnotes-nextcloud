/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	{
		// The shared config matches **/*.config.*, including PHP config files.
		ignores: ['**/*.php', 'js/**', 'l10n/**', 'vendor/**', 'node_modules/**', '.devenv/**', 'tests/e2e/playwright-report/**', 'tests/e2e/test-results/**'],
	},
	...recommendedJavascript,
]
