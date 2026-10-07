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

test('create a note, edit it and reload the page', async ({ page }) => {
	const title = uniqueName('E2E note')
	await page.goto(`${APP_PATH}/`)
	await page.getByRole('button', { name: 'New note' }).first().click()
	await expect(page).toHaveURL(/\/note\/\d+/u)

	// Replace the headline: the note is renamed like in QOwnNotes Desktop
	const editor = page.locator('.cm-content')
	await editor.click()
	await page.keyboard.press('ControlOrMeta+a')
	await page.keyboard.type(`# ${title}\n\nWritten in the browser`)
	await expect(page.locator('.note-editor__title h2')).toHaveText(title)
	await expect(page.locator('.note-editor__status')).toHaveText('')

	await page.reload()
	await expect(page.locator('.cm-content')).toContainText('Written in the browser')
	await expect(noteListItem(page, title)).toHaveCount(1)

	const noteId = Number(page.url().match(/\/note\/(\d+)/u)[1])
	const note = await api.get(`notes/${noteId}`)
	expect(note.title).toBe(title)
	expect(note.content).toBe(`# ${title}\n\nWritten in the browser`)
	await api.delete(`notes/${noteId}`)
})

test('moving a note to another folder keeps its tags', async ({ page }) => {
	const folder = uniqueName('E2E folder')
	const tag = uniqueName('E2E tag')
	const title = uniqueName('E2E moved')
	await api.post('subfolders', { path: `${folder}/Source` })
	await api.post('subfolders', { path: `${folder}/Target` })
	const note = await api.post('notes', { title, category: `${folder}/Source`, content: `# ${title}\n\n![image](../../media/e2e.png)\n` })
	await api.put(`note/${note.id}/tags`, { tagPaths: [[tag]] })

	await page.goto(`${APP_PATH}/folder/${encodeURIComponent(folder)}`)
	const target = page.locator(`[data-folder-path="${folder}/Target"]`)
	await expect(target).toBeVisible()
	await noteListItem(page, title).locator('a').first().dragTo(target.locator('.app-navigation-entry').first())

	await expect.poll(async () => (await api.get(`notes/${note.id}`)).category).toBe(`${folder}/Target`)
	expect(await noteTagPaths(api, note.id)).toEqual([tag])
	// The relative media link stays valid because the note has the same depth
	expect((await api.get(`notes/${note.id}`)).content).toContain('](../../media/e2e.png)')

	await api.delete(`subfolders?path=${encodeURIComponent(folder)}`)
	await deleteTags(api, [tag])
})

test('the tag filter shows only tagged notes', async ({ page }) => {
	const tag = uniqueName('E2E filter')
	const tagged = await api.post('notes', { title: uniqueName('E2E tagged'), content: 'tagged' })
	const untagged = await api.post('notes', { title: uniqueName('E2E untagged'), content: 'untagged' })
	await api.put(`note/${tagged.id}/tags`, { tagPaths: [[tag]] })

	await page.goto(`${APP_PATH}/`)
	await expect(noteListItem(page, untagged.title)).toHaveCount(1)
	await navigationEntry(page, tag).click()

	await expect(noteListItem(page, tagged.title)).toHaveCount(1)
	await expect(noteListItem(page, untagged.title)).toHaveCount(0)

	await navigationEntry(page, 'Untagged notes').click()
	await expect(noteListItem(page, untagged.title)).toHaveCount(1)
	await expect(noteListItem(page, tagged.title)).toHaveCount(0)

	await api.delete(`notes/${tagged.id}`)
	await api.delete(`notes/${untagged.id}`)
	await deleteTags(api, [tag])
})

test('tags can be added to a note in the editor', async ({ page }) => {
	const tag = uniqueName('E2E editor')
	const note = await api.post('notes', { title: uniqueName('E2E tagging'), content: 'text' })

	await page.goto(`${APP_PATH}/note/${note.id}`)
	const input = page.locator('.note-tag-editor input')
	await input.fill(`${tag}/Child`)
	await input.press('Enter')

	await expect.poll(() => noteTagPaths(api, note.id)).toEqual([`${tag}/Child`])
	await expect(noteListItem(page, note.title).locator('.note-list__tag')).toHaveText('Child')

	await api.delete(`notes/${note.id}`)
	await deleteTags(api, [tag])
})

test('a conflict with changes of another client is resolved', async ({ page }) => {
	const note = await api.post('notes', { title: uniqueName('E2E conflict'), content: 'original' })

	await page.goto(`${APP_PATH}/note/${note.id}`)
	await expect(page.locator('.cm-content')).toHaveText('original')
	await api.put(`notes/${note.id}`, { content: 'changed elsewhere' })

	await page.locator('.cm-content').click()
	await page.keyboard.press('End')
	await page.keyboard.type(' and mine')
	const dialog = page.getByRole('dialog', { name: 'The note was changed in the meantime' })
	await expect(dialog).toContainText('changed elsewhere')
	await dialog.getByRole('button', { name: 'Keep my version' }).click()

	await expect.poll(async () => (await api.get(`notes/${note.id}`)).content).toBe('original and mine')
	await api.delete(`notes/${note.id}`)
})

test('the preview renders tasks that can be checked', async ({ page }) => {
	const note = await api.post('notes', { title: uniqueName('E2E preview'), content: '# Tasks\n\n- [ ] first\n- [ ] second\n' })

	await page.goto(`${APP_PATH}/note/${note.id}`)
	await page.getByRole('button', { name: 'Preview' }).click()
	const preview = page.locator('.note-preview')
	await expect(preview.locator('h1')).toHaveText('Tasks')
	await preview.locator('input.task-list-item-checkbox').nth(1).click()

	await expect.poll(async () => (await api.get(`notes/${note.id}`)).content).toBe('# Tasks\n\n- [ ] first\n- [x] second\n')
	await page.getByRole('button', { name: 'Preview' }).click()
	await expect(page.locator('.cm-content')).toContainText('- [x] second')
	await api.delete(`notes/${note.id}`)
})

test('subfolders can be renamed in the navigation', async ({ page }) => {
	const folder = uniqueName('E2E tree')
	await api.post('subfolders', { path: folder })

	await page.goto(`${APP_PATH}/folder/${encodeURIComponent(folder)}`)
	const entry = page.locator(`[data-folder-path="${folder}"]`)
	await entry.locator('.app-navigation-entry').first().hover()
	await entry.getByRole('button', { name: 'Actions' }).first().click()
	await page.getByRole('button', { name: 'Rename subfolder' }).click()
	const renamed = `${folder} renamed`
	await entry.locator('input').first().fill(renamed)
	await entry.locator('input').first().press('Enter')

	await expect(page.locator(`[data-folder-path="${renamed}"]`)).toBeVisible()
	await expect(page).toHaveURL(new RegExp(`/folder/${encodeURIComponent(renamed)}$`, 'u'))
	const tree = await api.get('subfolders')
	expect(tree.children.map((child) => child.path)).toContain(renamed)

	await api.delete(`subfolders?path=${encodeURIComponent(renamed)}`)
})
