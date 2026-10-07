/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join } from 'node:path'

// Builds js/qownnotes-main.mjs (web interface) and js/qownnotes-files.mjs (Files app action); the styles are
// bundled into the scripts
export default createAppConfig(
	{
		main: join(import.meta.dirname, 'src', 'main.js'),
		files: join(import.meta.dirname, 'src', 'files.js'),
	},
	{
		inlineCSS: true,
		config: {
			test: {
				environment: 'jsdom',
				include: ['tests/js/**/*.test.js'],
			},
		},
	},
)
