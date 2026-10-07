/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { apiClient, APP_PATH, deleteTags, login, navigationEntry, noteListItem, noteTagPaths, uniqueName } from './helpers.js'

let api

test.beforeAll(async ({ baseURL }) => {
	api = await apiClient(baseURL)
})

test.afterAll(async () => {
	await api.dispose()
})

test.beforeEach(async ({ page }) => {
	await login(page)
})

/**
 * The tag tree of the API as flat list of {id, name, parentId, color}
 *
 * @param {object} tags the tag tree
 */
function flattenTags(tags) {
	return tags.flatMap((tag) => [tag, ...flattenTags(tag.children)])
}

test('a previous version of a note can be restored', async ({ page }) => {
	const note = await api.post('notes', { title: uniqueName('E2E versions'), content: 'first version' })
	// Versions are identified by their modification time in seconds
	await page.waitForTimeout(1100)
	await api.put(`notes/${note.id}`, { content: 'second version' })

	await page.goto(`${APP_PATH}/note/${note.id}`)
	await expect(page.locator('.cm-content')).toHaveText('second version')
	await page.getByRole('button', { name: 'Details and versions' }).click()
	const sidebar = page.locator('.note-sidebar')
	await expect(sidebar).toContainText(`${note.title}.md`)
	await sidebar.getByRole('tab', { name: 'Versions' }).click()
	await sidebar.getByRole('button', { name: 'Restore this version' }).first().click()

	await expect(page.locator('.cm-content')).toHaveText('first version')
	await expect.poll(async () => (await api.get(`notes/${note.id}`)).content).toBe('first version')
	await api.delete(`notes/${note.id}`)
})

test('a deleted note can be restored with its tags', async ({ page }) => {
	const tag = uniqueName('E2E trash')
	const note = await api.post('notes', { title: uniqueName('E2E deleted'), content: 'deleted text' })
	await api.put(`note/${note.id}/tags`, { tagPaths: [[tag]] })
	await page.goto(`${APP_PATH}/`)
	await expect(noteListItem(page, note.title)).toHaveCount(1)

	// Deleted like by the web interface, which marks the tag links stale
	await page.evaluate(async (id) => {
		await fetch(`${window.OC.webroot}/index.php/apps/qownnotes/api/v1/notes/${id}`, {
			method: 'DELETE',
			headers: { requesttoken: window.OC.requestToken, 'X-QOwnNotes-Relink-Tags': '1' },
		})
	}, note.id)

	await navigationEntry(page, 'Deleted notes').click()
	const dialog = page.getByRole('dialog', { name: 'Deleted notes' })
	await dialog.getByRole('button', { name: `Restore ${note.title}` }).first().click()

	await expect(page).toHaveURL(/\/note\/\d+/u)
	await expect(page.locator('.cm-content')).toHaveText('deleted text')
	const restoredId = Number(page.url().match(/\/note\/(\d+)/u)[1])
	expect(await noteTagPaths(api, restoredId)).toEqual([tag])
	await api.delete(`notes/${restoredId}`)
	await deleteTags(api, [tag])
})

test('several notes can be tagged at once', async ({ page }) => {
	const tag = uniqueName('E2E bulk')
	const first = await api.post('notes', { title: uniqueName('E2E bulk one'), content: 'one' })
	const second = await api.post('notes', { title: uniqueName('E2E bulk two'), content: 'two' })

	await page.goto(`${APP_PATH}/`)
	await noteListItem(page, first.title).locator('a').first().click({ modifiers: ['ControlOrMeta'] })
	await noteListItem(page, second.title).locator('a').first().click({ modifiers: ['ControlOrMeta'] })
	const toolbar = page.getByRole('toolbar', { name: 'Selected notes' })
	await expect(toolbar).toContainText('2 notes selected')

	await toolbar.getByRole('button', { name: 'Actions' }).click()
	const input = page.getByRole('textbox', { name: 'Add tag' })
	await input.fill(`${tag}/Child`)
	await input.press('Enter')

	await expect.poll(() => noteTagPaths(api, first.id)).toEqual([`${tag}/Child`])
	expect(await noteTagPaths(api, second.id)).toEqual([`${tag}/Child`])

	await page.keyboard.press('Escape')
	await toolbar.getByRole('button', { name: 'Clear selection' }).click()
	await expect(toolbar).toHaveCount(0)
	await api.delete(`notes/${first.id}`)
	await api.delete(`notes/${second.id}`)
	await deleteTags(api, [tag])
})

test('tags can be moved by drag and drop and their color removed', async ({ page }) => {
	// Names that are sorted first, so both tags are visible without scrolling the navigation
	const parent = uniqueName('0 E2E parent')
	const child = uniqueName('0 E2E child')
	await api.post('tags/batch', { operations: [{ op: 'create', name: parent }, { op: 'create', name: child, color: '#ff0000' }] })

	await page.goto(`${APP_PATH}/`)
	// The navigation must not change while dragging
	await page.waitForLoadState('networkidle')
	await navigationEntry(page, child).dragTo(navigationEntry(page, parent))
	await expect.poll(async () => {
		const all = flattenTags((await api.get('tags')).tags)
		return all.find((tag) => tag.name === child)?.parentId === all.find((tag) => tag.name === parent)?.id
	}).toBe(true)

	// The child tag is shown once its parent is expanded
	await page.locator('.app-navigation-entry-wrapper', { has: navigationEntry(page, parent) }).first().getByRole('button', { name: 'Open menu' }).first().click()
	const entry = navigationEntry(page, child)
	await entry.hover()
	await entry.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('button', { name: 'Change color' }).click()
	const dialog = page.getByRole('dialog', { name: /Color of the tag/u })
	await dialog.getByRole('button', { name: 'Remove color' }).first().click()
	await dialog.getByRole('button', { name: 'Save' }).click()
	await expect.poll(async () => flattenTags((await api.get('tags')).tags).find((tag) => tag.name === child)?.color ?? null).toBeNull()

	const parentId = flattenTags((await api.get('tags')).tags).find((tag) => tag.name === parent).id
	await api.delete(`tags/${parentId}`)
})

test('notes can be opened from the Files app', async ({ page }) => {
	const note = await api.post('notes', { title: uniqueName('E2E files'), content: 'from files' })
	const { notesPath } = await api.get('settings')

	await page.goto(`/index.php/apps/files/files?dir=/${encodeURIComponent(notesPath)}`)
	const row = page.locator(`[data-cy-files-list-row-name="${note.title}.md"]`)
	await row.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Open in QOwnNotes' }).click()

	await expect(page).toHaveURL(new RegExp(`/apps/qownnotes/note/${note.id}$`, 'u'))
	await expect(page.locator('.cm-content')).toHaveText('from files')
	await api.delete(`notes/${note.id}`)
})
