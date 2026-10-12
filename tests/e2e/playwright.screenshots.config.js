/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

// Creates the screenshots in docs/screenshots (app store and README), see `just screenshots`.
// Runs against the docker development server (see docker/README.md) or the server in NEXTCLOUD_URL.
export default defineConfig({
	testDir: './screenshots',
	timeout: 120 * 1000,
	expect: { timeout: 10 * 1000 },
	fullyParallel: false,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: process.env.NEXTCLOUD_URL ?? 'http://localhost:8081',
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1440, height: 900 },
				deviceScaleFactor: 1,
				colorScheme: 'light',
				reducedMotion: 'reduce',
				locale: 'en-US',
				timezoneId: 'UTC',
				launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
			},
		},
	],
})
