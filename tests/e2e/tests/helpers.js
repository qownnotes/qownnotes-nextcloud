/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request } from '@playwright/test'

export const USER = process.env.NEXTCLOUD_USER ?? 'admin'
export const PASSWORD = process.env.NEXTCLOUD_PASSWORD ?? 'admin'
export const APP_PATH = '/index.php/apps/qownnotes'

/**
 * A unique name, so tests don't depend on the notes of earlier runs
 *
 * @param {string} prefix the prefix
 */
export function uniqueName(prefix) {
	return `${prefix} ${Date.now().toString(36)}${Math.floor(Math.random() * 1000)}`
}

/**
 * @param {import('@playwright/test').Page} page the page
 */
export async function login(page) {
	await page.goto('/index.php/login')
	await page.fill('#user', USER)
	await page.fill('#password', PASSWORD)
	await page.click('button[type=submit]')
	await page.waitForURL(/\/apps\//u)
}

/**
 * An API client with basic auth, for preparing and checking data
 *
 * @param {string} baseURL the server URL
 */
export async function apiClient(baseURL) {
	const context = await request.newContext({
		baseURL,
		extraHTTPHeaders: {
			Authorization: 'Basic ' + Buffer.from(`${USER}:${PASSWORD}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})

	const call = async (method, path, data, headers = {}) => {
		const response = await context.fetch(`${APP_PATH}/api/v1/${path}`, { method, data, headers })
		expect(response.ok(), `${method} ${path}: ${response.status()} ${await response.text()}`).toBeTruthy()
		const text = await response.text()
		return text === '' ? null : JSON.parse(text)
	}

	return {
		get: (path) => call('GET', path),
		post: (path, data) => call('POST', path, data),
		put: (path, data, headers) => call('PUT', path, data, headers),
		delete: (path) => call('DELETE', path),
		dispose: () => context.dispose(),
	}
}

/**
 * The tags of a note as name paths
 *
 * @param {object} api the API client
 * @param {number} noteId the note ID
 */
export async function noteTagPaths(api, noteId) {
	const { tags } = await api.get(`note/${noteId}/tags`)
	return tags.map((tag) => tag.path.join('/'))
}

/**
 * The list item of a note in the note list
 *
 * @param {import('@playwright/test').Page} page the page
 * @param {string} title the note title
 */
export function noteListItem(page, title) {
	return page.locator('.note-list__item').filter({ has: page.locator('.list-item-content__name', { hasText: new RegExp(`^${escapeRegExp(title)}$`, 'u') }) })
}

/**
 * @param {string} text the text
 */
function escapeRegExp(text) {
	return text.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&')
}

/**
 * An entry of the app navigation (subfolders, tags)
 *
 * @param {import('@playwright/test').Page} page the page
 * @param {string} name the name of the entry
 */
export function navigationEntry(page, name) {
	return page.locator('.app-navigation-entry').filter({ has: page.locator('.app-navigation-entry__name', { hasText: new RegExp(`^\\s*${escapeRegExp(name)}\\s*$`, 'u') }) }).first()
}

/**
 * Deletes top-level tags (with their child tags) created by a test
 *
 * @param {object} api the API client
 * @param {string[]} names the tag names
 */
export async function deleteTags(api, names) {
	const { tags } = await api.get('tags')
	for (const tag of tags.filter((tag) => names.includes(tag.name))) {
		await api.delete(`tags/${tag.id}`)
	}
}
