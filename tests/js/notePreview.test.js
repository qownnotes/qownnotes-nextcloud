/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import NotePreview from '../../src/components/NotePreview.vue'
import { toggleTask } from '../../src/utils/markdown.js'

vi.mock('../../src/api.js', () => ({ API_BASE: '/apps/qownnotes/api/v1' }))

let app
let container

afterEach(() => {
	app?.unmount()
	container?.remove()
})

/**
 * Mounts the preview with the same shared draft binding as the note editor.
 *
 * @param {object} content reactive draft text
 */
function mountPreview(content) {
	container = document.createElement('div')
	document.body.append(container)
	app = createApp({
		render: () => h(NotePreview, {
			content: content.value,
			noteId: 1,
			onToggleTask: (index) => { content.value = toggleTask(content.value, index) },
		}),
	})
	app.mount(container)
}

describe('live note preview', () => {
	it('renders draft edits immediately without a server save', async () => {
		const content = ref('# Original')
		mountPreview(content)
		expect(container.querySelector('h1').textContent).toBe('Original')
		content.value = '# Draft\n\n**Typed text**'
		await nextTick()
		expect(container.querySelector('h1').textContent).toBe('Draft')
		expect(container.querySelector('strong').textContent).toBe('Typed text')
	})

	it('shares task changes with the editor draft', async () => {
		const content = ref('- [ ] task')
		mountPreview(content)
		container.querySelector('input').click()
		await nextTick()
		expect(content.value).toBe('- [x] task')
		expect(container.querySelector('input').checked).toBe(true)
	})
})
