/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

// Runs against the docker development server (see docker/README.md) or the server in NEXTCLOUD_URL.
// On NixOS, use the system browser with CHROMIUM_PATH=$(which chromium).
export default defineConfig({
	testDir: './tests',
	timeout: 60 * 1000,
	expect: { timeout: 10 * 1000 },
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'list' : [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: process.env.NEXTCLOUD_URL ?? 'http://localhost:8081',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1400, height: 900 },
				launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
			},
		},
	],
})
