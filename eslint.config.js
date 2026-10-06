/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	{
		ignores: ['js/**', 'vendor/**', 'node_modules/**', '.devenv/**', 'tests/e2e/playwright-report/**', 'tests/e2e/test-results/**'],
	},
	...recommendedJavascript,
]
