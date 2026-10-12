/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request, test } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import { apiClient, APP_PATH, login, navigationEntry, noteListItem, PASSWORD, USER } from '../tests/helpers.js'

// A fresh demo user, so the screenshots only show the demo notes
const DEMO_USER = 'demo'
const DEMO_DISPLAY_NAME = 'Demo'
const DEMO_PASSWORD = 'Screenshot-Demo-2026!'
const SCREENSHOT_DIR = fileURLToPath(new URL('../../../docs/screenshots/', import.meta.url))

const HOUR = 60 * 60
const WELCOME_TITLE = 'Welcome to QOwnNotes'

const NOTES = [
	{
		title: WELCOME_TITLE,
		category: '',
		favorite: true,
		age: 50 * HOUR,
		tags: [['Getting started']],
		content: `# ${WELCOME_TITLE}

QOwnNotes is a **plain-text file notepad** with Markdown support and todo list manager.

- Notes are stored as Markdown files in your Nextcloud
- Organize notes in **subfolders** and with **tags**
- Edit them in QOwnNotes Desktop, on Android or here on the web

## Tasks

- [x] Install QOwnNotes on the desktop
- [x] Sync the note folder with Nextcloud
- [ ] Try the web interface
- [ ] Tag a few notes

> Tags are stored in \`notes.sqlite\`, so they are the same in all clients.
`,
	},
	{
		title: 'Pancakes',
		category: 'Recipes',
		age: 5 * HOUR,
		tags: [['Private']],
		content: '# Pancakes\n\n- 200 g flour\n- 2 eggs\n- 300 ml milk\n- 1 pinch of salt\n',
	},
	{
		title: 'Packing list',
		category: 'Private/Travel',
		age: 10 * HOUR,
		tags: [['Private']],
		content: '# Packing list\n\n- [ ] Passport\n- [ ] Charger\n- [ ] Sunscreen\n',
	},
	{
		title: 'Book ideas',
		category: 'Private',
		age: 15 * HOUR,
		tags: [['Private']],
		content: '# Book ideas\n\n- A note-taking detective\n- The lost Markdown file\n',
	},
	{
		title: 'Website relaunch',
		category: 'Work/Projects',
		age: 20 * HOUR,
		tags: [['Work', 'Projects']],
		content: '# Website relaunch\n\n## Milestones\n\n- [x] Design\n- [ ] Content\n- [ ] Launch\n',
	},
	{
		title: 'Project roadmap',
		category: 'Work',
		age: 26 * HOUR,
		tags: [['Important'], ['Work']],
		content: '# Project roadmap\n\n| Quarter | Goal |\n| --- | --- |\n| Q1 | Beta |\n| Q2 | Release |\n',
	},
	{
		title: 'Meeting notes 2026-10-05',
		category: 'Work',
		age: 28 * HOUR,
		tags: [['Work', 'Meetings']],
		content: '# Meeting notes 2026-10-05\n\n## Attendees\n\n- Alice\n- Bob\n',
	},
	{
		title: 'Shopping list',
		category: '',
		age: 30 * HOUR,
		tags: [['Private']],
		content: '# Shopping list\n\n- [ ] Bread\n- [ ] Coffee\n- [ ] Apples\n',
	},
]

const TAG_COLORS = {
	'Getting started': '#2ec27e',
	Important: '#e01b24',
}

// Hide the blinking text cursor and the scrollbars, so the screenshots don't change between runs
const SCREENSHOT_CSS = `
	.cm-cursorLayer, .cm-dropCursor { display: none !important; }
	* { caret-color: transparent !important; scrollbar-width: none !important; }
`

let api
const noteIds = {}

/**
 * Recreates the demo user with the OCS provisioning API of the admin
 *
 * @param {string} baseURL the server URL
 */
async function recreateDemoUser(baseURL) {
	const admin = await request.newContext({
		baseURL,
		extraHTTPHeaders: {
			Authorization: 'Basic ' + Buffer.from(`${USER}:${PASSWORD}`).toString('base64'),
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
	try {
		await admin.delete(`/ocs/v2.php/cloud/users/${DEMO_USER}`)
		const response = await admin.post('/ocs/v2.php/cloud/users', {
			form: { userid: DEMO_USER, password: DEMO_PASSWORD, displayName: DEMO_DISPLAY_NAME },
		})
		expect(response.ok(), `create user: ${response.status()} ${await response.text()}`).toBeTruthy()
	} finally {
		await admin.dispose()
	}
}

/**
 * Creates the demo notes, tags and tag colors
 */
async function createDemoNotes() {
	const now = Math.floor(Date.now() / 1000)
	for (const note of NOTES) {
		const created = await api.post('notes', {
			title: note.title,
			category: note.category,
			content: note.content,
			favorite: note.favorite ?? false,
		})
		await api.put(`note/${created.id}/tags`, { tagPaths: note.tags })
		// Set the modification time last, because tagging may touch the note
		await api.put(`notes/${created.id}`, { modified: now - note.age })
		noteIds[note.title] = created.id
	}

	const { tags } = await api.get('tags')
	for (const tag of tags) {
		if (TAG_COLORS[tag.name]) {
			await api.patch(`tags/${tag.id}`, { color: TAG_COLORS[tag.name] })
		}
	}
}

/**
 * Opens the welcome note in a stable state for screenshots
 *
 * @param {import('@playwright/test').Page} page the page
 */
async function openWelcomeNote(page) {
	await page.goto(`${APP_PATH}/note/${noteIds[WELCOME_TITLE]}`)
	await expect(page.locator('.note-editor__title h2')).toHaveText(WELCOME_TITLE)
	await expect(noteListItem(page, 'Shopping list')).toHaveCount(1)
	await expect(navigationEntry(page, 'Getting started')).toBeVisible()
	await page.addStyleTag({ content: SCREENSHOT_CSS })
}

/**
 * @param {import('@playwright/test').Page} page the page
 * @param {string} name the file name without extension
 */
async function screenshot(page, name) {
	// Move the mouse away, so no hover effects or tooltips are visible
	await page.mouse.move(0, 0)
	await page.evaluate(() => document.activeElement?.blur())
	await page.evaluate(() => document.fonts.ready)
	await page.screenshot({ path: `${SCREENSHOT_DIR}${name}.png`, animations: 'disabled' })
}

test.beforeAll(async ({ baseURL }) => {
	await recreateDemoUser(baseURL)
	api = await apiClient(baseURL, DEMO_USER, DEMO_PASSWORD)
	await createDemoNotes()
})

test.afterAll(async () => {
	await api?.dispose()
})

test.beforeEach(async ({ page }) => {
	await login(page, DEMO_USER, DEMO_PASSWORD)
})

test('editor', async ({ page }) => {
	await openWelcomeNote(page)
	await expect(page.locator('.cm-content')).toContainText('Tag a few notes')
	await screenshot(page, 'editor')
})

test('preview', async ({ page }) => {
	await openWelcomeNote(page)
	await page.getByRole('button', { name: 'Preview', exact: true }).click()
	await expect(page.locator('.note-preview h1')).toHaveText(WELCOME_TITLE)
	await screenshot(page, 'preview')
})
