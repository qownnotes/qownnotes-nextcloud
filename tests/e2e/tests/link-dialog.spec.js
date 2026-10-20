/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { apiClient, APP_PATH, login, uniqueName } from './helpers.js'

test('Ctrl+L inserts a fetched web title and a relative link to another note', async ({ page, baseURL }) => {
	const api = await apiClient(baseURL)
	const folder = uniqueName('E2E links')
	const title = uniqueName('Link source')
	const targetTitle = uniqueName('Link target')
	let source
	try {
		await api.post('subfolders', { path: folder })
		source = await api.post('notes', { title, content: `# ${title}\n\nSelected text` })
		await api.post('notes', { title: targetTitle, category: folder, content: `# ${targetTitle}` })
		await login(page)
		await page.route('**/apps/qownnotes/link/title', (route) => route.fulfill({ json: { title: 'Fetched page title' } }))
		await page.goto(`${APP_PATH}/note/${source.id}`)
		const editor = page.locator('.cm-content')
		await editor.click()
		await page.keyboard.press('ControlOrMeta+End')
		await page.keyboard.press('ControlOrMeta+l')
		const dialog = page.getByRole('dialog', { name: 'Insert link' })
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox', { name: 'URL', exact: true }).fill('https://example.com')
		await expect(dialog.getByRole('textbox', { name: 'Link text', exact: true })).toHaveValue('Fetched page title')
		await dialog.getByRole('button', { name: 'Insert link', exact: true }).click()
		await expect(dialog).not.toBeVisible()
		await expect(editor).toContainText('[Fetched page title](https://example.com/)')
		await page.keyboard.press('ControlOrMeta+z')
		await expect(editor).not.toContainText('Fetched page title')
		await page.keyboard.press('ControlOrMeta+Shift+l')
		await expect(dialog.getByRole('tab', { name: 'Link to note' })).toHaveAttribute('aria-selected', 'true')
		await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(dialog).not.toBeVisible()
		await page.locator('.note-editor__actions').getByRole('button', { name: 'Actions', exact: true }).click()
		await page.getByRole('menuitem', { name: 'Link to note (Ctrl+Shift+L)', exact: true }).click()
		await expect(dialog.getByRole('tab', { name: 'Link to note' })).toHaveAttribute('aria-selected', 'true')
		await expect(dialog.getByRole('textbox', { name: 'Search notes', exact: true })).toBeFocused()
		await dialog.getByRole('textbox', { name: 'Search notes', exact: true }).fill(targetTitle)
		await dialog.getByRole('button', { name: `${targetTitle} (${folder})`, exact: true }).click()
		await dialog.getByRole('button', { name: 'Insert link', exact: true }).click()
		await expect(editor).toContainText(`[${targetTitle}](${encodeURIComponent(folder)}/${encodeURIComponent(targetTitle)}.md)`)
		await expect.poll(async () => (await api.get(`notes/${source.id}`)).content).toContain(`[${targetTitle}]`)
	} finally {
		if (source) {
			await api.delete(`notes/${source.id}`)
		}
		await api.delete(`subfolders?path=${encodeURIComponent(folder)}`)
		await api.dispose()
	}
})
