/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { apiClient, APP_PATH, deleteTags, login, navigationEntry, uniqueName } from './helpers.js'

test('the bookmarks dialog lists, edits and deletes bookmarks of bookmark notes', async ({ page, baseURL }) => {
	const api = await apiClient(baseURL)
	const id = Date.now().toString(36)
	const folder = uniqueName('E2E bookmarks')
	const parentTag = uniqueName('E2E bookmark tag')
	const alpha = `https://alpha.example/${id}`
	const beta = `https://beta.example/${id}`
	const notes = []
	try {
		await api.post('subfolders', { path: folder })
		// Found by its name, like in QOwnNotes Desktop
		notes.push(await api.post('notes', {
			title: 'Bookmarks',
			category: folder,
			content: `# Bookmarks\n\n- [Alpha ${id}](${alpha}) #e2e first\n- [Beta ${id}](${beta})\n`,
		}))
		// Found by its tag "bookmarks"
		const tagged = await api.post('notes', {
			title: uniqueName('Links'),
			category: folder,
			content: `# Links\n\n* [Alpha ${id}](${alpha}) #other\nText\n`,
		})
		notes.push(tagged)
		await api.put(`note/${tagged.id}/tags`, { tagPaths: [[parentTag, 'bookmarks']] })

		await login(page)
		await page.goto(APP_PATH)
		await navigationEntry(page, 'Bookmarks').click()
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByRole('heading', { name: 'Bookmarks' })).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Search bookmarks', exact: true }).fill(id)
		const items = dialog.getByRole('listitem')
		await expect(items).toHaveCount(2)
		await expect(items.first()).toContainText(`Alpha ${id}`)
		await expect(items.first()).toContainText('#e2e')
		await expect(items.first()).toContainText('#other')
		await expect(items.first().getByRole('link', { name: `Alpha ${id}` })).toHaveAttribute('href', alpha)

		// The tags of a bookmark select the tag filter
		await items.first().getByRole('button', { name: '#other', exact: true }).click()
		await expect(items).toHaveCount(1)
		await expect(dialog.getByText('1 of ', { exact: false })).toBeVisible()
		await dialog.getByRole('group', { name: 'Filter by tag' }).getByRole('button').filter({ hasText: '#other' }).click()
		await expect(items).toHaveCount(2)
		await items.first().getByRole('button', { name: '#other', exact: true }).click()
		await expect(items).toHaveCount(1)
		await dialog.getByRole('button', { name: 'Clear filters', exact: true }).click()
		await expect(dialog.getByRole('textbox', { name: 'Search bookmarks', exact: true })).toHaveValue('')
		await dialog.getByRole('textbox', { name: 'Search bookmarks', exact: true }).fill(id)
		await expect(items).toHaveCount(2)

		// Escape returns to the list
		await dialog.getByRole('button', { name: `Edit Alpha ${id}`, exact: true }).click()
		await expect(dialog.getByRole('heading', { name: 'Edit bookmark' })).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(dialog.getByRole('heading', { name: 'Bookmarks' })).toBeVisible()
		await expect(dialog.getByRole('button', { name: `Edit Alpha ${id}`, exact: true })).toBeFocused()

		await dialog.getByRole('button', { name: `Edit Alpha ${id}`, exact: true }).click()
		await expect(dialog.getByRole('heading', { name: 'Edit bookmark' })).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Name', exact: true }).fill('Bad [name]')
		await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeDisabled()
		await dialog.getByRole('textbox', { name: 'Name', exact: true }).fill(`Gamma ${id}`)
		await dialog.getByRole('textbox', { name: 'Tags', exact: true }).fill('#new')
		await dialog.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(dialog.getByRole('heading', { name: 'Bookmarks' })).toBeVisible()
		await expect(items.filter({ hasText: `Gamma ${id}` })).toHaveCount(1)
		await expect.poll(async () => (await api.get(`notes/${notes[0].id}`)).content)
			.toBe(`# Bookmarks\n\n- [Gamma ${id}](${alpha}) #new first\n- [Beta ${id}](${beta})\n`)
		await expect.poll(async () => (await api.get(`notes/${tagged.id}`)).content)
			.toBe(`# Links\n\n* [Gamma ${id}](${alpha}) #new first\nText\n`)

		await dialog.getByRole('button', { name: `Delete Beta ${id}`, exact: true }).click()
		await expect(dialog.getByRole('heading', { name: 'Delete bookmark' })).toBeVisible()
		await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
		await expect(items).toHaveCount(1)
		await expect.poll(async () => (await api.get(`notes/${notes[0].id}`)).content)
			.toBe(`# Bookmarks\n\n- [Gamma ${id}](${alpha}) #new first\n`)

		await dialog.getByRole('button', { name: `${folder}/${tagged.title}`, exact: true }).click()
		await expect(dialog).not.toBeVisible()
		await expect(page).toHaveURL(new RegExp(`/note/${tagged.id}`, 'u'))
	} finally {
		for (const note of notes) {
			await api.delete(`notes/${note.id}`)
		}
		await api.delete(`subfolders?path=${encodeURIComponent(folder)}`)
		await deleteTags(api, [parentTag])
		await api.dispose()
	}
})
