/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join } from 'node:path'

// Builds js/qownnotes-main.mjs; the styles are bundled into the script
export default createAppConfig(
	{
		main: join(import.meta.dirname, 'src', 'main.js'),
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
