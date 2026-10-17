/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { apiClient, APP_PATH, deleteTags, login, navigationEntry, noteListItem, uniqueName } from './helpers.js'

test('the app can be hidden from the app navigation in the settings', async ({ page, baseURL }) => {
	const api = await apiClient(baseURL)
	// The entries of the app menu, as the Nextcloud header gets them
	const navigationAppIds = async () => {
		const response = await page.request.get('/ocs/v2.php/core/navigation/apps', { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })
		expect(response.ok()).toBeTruthy()
		return (await response.json()).ocs.data.map((entry) => entry.id)
	}
	const toggleHideNavigation = async () => {
		await navigationEntry(page, 'Settings').click()
		const dialog = page.getByRole('dialog', { name: 'QOwnNotes settings' })
		await dialog.getByText('Hide QOwnNotes from the app navigation').click()
		await dialog.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(dialog).toHaveCount(0)
		await page.reload()
	}
	try {
		await login(page)
		await page.goto(APP_PATH)
		expect(await navigationAppIds()).toContain('qownnotes')

		await toggleHideNavigation()
		expect((await api.get('settings')).hideNavigation).toBe(true)
		expect(await navigationAppIds()).not.toContain('qownnotes')

		// The web interface stays available, so the setting can be turned off again
		await toggleHideNavigation()
		expect((await api.get('settings')).hideNavigation).toBe(false)
		expect(await navigationAppIds()).toContain('qownnotes')
	} finally {
		await api.put('settings', { hideNavigation: false })
		await api.dispose()
	}
})

test('the editor cursor follows light and dark theme colors without reloading', async ({ page, baseURL }) => {
	const api = await apiClient(baseURL)
	let note
	try {
		note = await api.post('notes', { title: uniqueName('UI cursor'), content: 'Check the cursor' })
		await login(page)
		await page.goto(`${APP_PATH}/note/${note.id}`)
		const editor = page.locator('.markdown-editor')
		const content = editor.locator('.cm-content')
		await content.click()
		const cursor = editor.locator('.cm-cursor-primary')
		await expect(cursor).toBeVisible()
		// Nextcloud changes these CSS variables when switching themes.
		for (const [text, background] of [['rgb(238, 238, 238)', 'rgb(24, 24, 24)'], ['rgb(34, 34, 34)', 'rgb(255, 255, 255)']]) {
			await editor.evaluate((element, colors) => {
				element.style.setProperty('--color-main-text', colors.text)
				element.style.setProperty('--color-main-background', colors.background)
			}, { text, background })
			await expect(editor.locator('.cm-editor')).toHaveCSS('background-color', background)
			await expect(content).toHaveCSS('color', text)
			await expect(cursor).toHaveCSS('border-left-color', text)
		}
	} finally {
		if (note) {
			await api.delete(`notes/${note.id}`)
		}
		await api.dispose()
	}
})

for (const width of [1400, 390]) {
	test.describe(`UI at ${width}px`, () => {
		test.use({ viewport: { width, height: 844 } })

		test('settings actions stay visible and Cancel discards edits', async ({ page, baseURL }) => {
			const api = await apiClient(baseURL)
			try {
				const settings = await api.get('settings')
				await login(page)
				await page.goto(APP_PATH)
				// On mobile, open the navigation before using its footer.
				if (width < 600) {
					await page.getByRole('button', { name: 'Open navigation' }).click()
				}
				await navigationEntry(page, 'Settings').click()
				const dialog = page.getByRole('dialog', { name: 'QOwnNotes settings' })
				await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeInViewport()
				await expect(dialog.getByRole('group', { name: 'Title style for new notes' })).toBeVisible()
				await dialog.getByRole('textbox', { name: 'Path of the note folder' }).fill('Unsaved folder')
				await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
				await expect(dialog).toHaveCount(0)
				if (width < 600) {
					const toggle = page.locator('.app-navigation-toggle')
					await expect(toggle).toBeVisible()
					if (await toggle.getAttribute('aria-expanded') === 'false') {
						await toggle.click()
					}
				}
				await navigationEntry(page, 'Settings').click()
				await expect(dialog.getByRole('textbox', { name: 'Path of the note folder' })).toHaveValue(settings.notesPath)
			} finally {
				await api.dispose()
			}
		})

		test('deleted notes preview fits and follows the selection after restoring', async ({ page }) => {
			const notes = [
				{ title: 'First deleted note', originalLocation: 'Notes/first.md', deleted: 1791800000, subFolderPath: 'A very long folder path/'.repeat(8), content: 'First preview' },
				{ title: 'Second deleted note', originalLocation: 'Notes/second.md', deleted: 1791700000, content: 'Second preview' },
			]
			await page.route('**/api/v1/trash', (route) => route.fulfill({ json: { notes } }))
			await page.route('**/api/v1/trash/restore', (route) => route.fulfill({ json: { id: null } }))
			await login(page)
			await page.goto(APP_PATH)
			if (width < 600) {
				await page.getByRole('button', { name: 'Open navigation' }).click()
			}
			await navigationEntry(page, 'Deleted notes').click()
			const dialog = page.getByRole('dialog', { name: 'Deleted notes' })
			const preview = dialog.locator('.trash-dialog__preview')
			await expect(preview).toHaveText('First preview')
			await expect(dialog.getByRole('button', { name: /First deleted note.*A very long/u })).toHaveAttribute('aria-pressed', 'true')
			expect(await dialog.locator('.trash-dialog__content').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
			await dialog.getByRole('button', { name: 'Restore First deleted note', exact: true }).click()
			await expect(preview).toHaveText('Second preview')
			await preview.focus()
			await expect(preview).toBeFocused()
		})

		test('tag color controls fit without horizontal scrolling', async ({ page, baseURL }) => {
			const api = await apiClient(baseURL)
			const name = uniqueName('0 UI color')
			try {
				await api.post('tags/batch', { operations: [{ op: 'create', name, color: '#ff0000', darkColor: '#00ff00' }] })
				await login(page)
				await page.goto(APP_PATH)
				if (width < 600) {
					await page.getByRole('button', { name: 'Open navigation' }).click()
				}
				const entry = navigationEntry(page, name)
				await entry.hover()
				await entry.getByRole('button', { name: 'Actions' }).click()
				await page.getByRole('button', { name: 'Change color' }).click()
				const dialog = page.getByRole('dialog', { name: /Color of the tag/u })
				await expect(dialog.getByRole('button', { name: 'Remove color (Color)', exact: true })).toBeVisible()
				await expect(dialog.getByRole('button', { name: 'Remove color (Color in dark themes)', exact: true })).toBeVisible()
				for (const row of await dialog.locator('.tag-color-dialog__row').all()) {
					expect(await row.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
				}
				await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
			} finally {
				await deleteTags(api, [name])
				await api.dispose()
			}
		})

		test('notes selected with Ctrl+click are highlighted', async ({ page, baseURL }) => {
			const api = await apiClient(baseURL)
			const notes = []
			try {
				notes.push(await api.post('notes', { title: uniqueName('UI selected one'), content: 'one' }))
				notes.push(await api.post('notes', { title: uniqueName('UI selected two'), content: 'two' }))
				await login(page)
				await page.goto(APP_PATH)
				for (const note of notes) {
					await noteListItem(page, note.title).locator('a').first().click({ modifiers: ['ControlOrMeta'] })
				}
				await page.mouse.move(0, 0)
				for (const note of notes) {
					await expect(noteListItem(page, note.title).locator('.list-item')).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')
				}
			} finally {
				for (const note of notes) {
					await api.delete(`notes/${note.id}`)
				}
				await api.dispose()
			}
		})

		test('long note titles do not push editor actions out of view', async ({ page, baseURL }) => {
			const api = await apiClient(baseURL)
			let note
			try {
				note = await api.post('notes', { title: uniqueName('UI long title '.repeat(12)), content: 'Check the toolbar' })
				await login(page)
				await page.goto(`${APP_PATH}/note/${note.id}`)
				const editor = page.locator('.note-editor')
				await expect(editor.getByRole('button', { name: 'Preview', exact: true })).toBeInViewport()
				await expect(editor.getByRole('button', { name: 'Details and versions', exact: true })).toBeInViewport()
				expect(await editor.locator('.note-editor__title-row').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
			} finally {
				if (note) {
					await api.delete(`notes/${note.id}`)
				}
				await api.dispose()
			}
		})
	})
}
