/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { undo } from '@codemirror/commands'
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import MarkdownEditor from '../../src/components/MarkdownEditor.vue'
import { markdownLink, relativeNoteLink } from '../../src/utils/insertLink.js'
import { renderMarkdown } from '../../src/utils/markdown.js'
import { findLinkedNote } from '../../src/utils/noteLinks.js'

let app
let container

afterEach(() => {
	app?.unmount()
	container?.remove()
})

describe('insert link formatting', () => {
	it.each(['', 'Work', 'Work/Projects', 'Personal/Journal'])('resolves relative note links from %s', (category) => {
		const note = { id: 2, title: 'Display title', category: 'Work', internalPath: 'Notes/Work/My # note (é).txt' }
		const href = relativeNoteLink(category, note)
		expect(findLinkedNote([note], category, href)).toBe(note)
		expect(href).toContain('My%20%23%20note%20(%C3%A9).txt')
	})

	it('escapes titles and destinations without double-encoding file links', () => {
		const href = 'My%20note%23(1).md'
		const link = markdownLink('A [title] *with* \\ escapes <b>', href)
		container = document.createElement('div')
		container.innerHTML = renderMarkdown(link, { apiBase: '/api', noteId: 1 })
		const anchor = container.querySelector('a')
		expect(anchor.textContent).toBe('A [title] *with* \\ escapes <b>')
		expect(anchor.dataset.href).toBe('My%20note%23%281%29.md')
		expect(container.querySelector('b')).toBeNull()
	})

	it('uses the URL when link text is empty', () => {
		expect(markdownLink('', 'https://example.com')).toBe('[https://example.com](https://example.com)')
	})
})

describe('editor link dialog shortcut', () => {
	it.each([false, true])('opens the correct link tab with Shift=%s and replaces the selection in one undoable edit', (shiftKey) => {
		container = document.createElement('div')
		document.body.append(container)
		let selected
		let tab
		app = createApp({
			render: () => h(MarkdownEditor, {
				modelValue: 'Before selected after',
				onInsertLink: (text, initialTab) => {
					selected = text
					tab = initialTab
				},
			}),
		})
		const root = app.mount(container)
		const editor = root.$.subTree.component.proxy
		editor.view.dispatch({ selection: { anchor: 7, head: 15 } })
		editor.focus()
		const event = new KeyboardEvent('keydown', { key: shiftKey ? 'L' : 'l', code: 'KeyL', keyCode: 76, ctrlKey: true, shiftKey, bubbles: true, cancelable: true })
		editor.view.contentDOM.dispatchEvent(event)
		expect(event.defaultPrevented).toBe(true)
		expect(selected).toBe('selected')
		expect(tab).toBe(shiftKey ? 'note' : 'web')
		editor.insertText('[selected](https://example.com)')
		expect(editor.view.state.doc.toString()).toBe('Before [selected](https://example.com) after')
		undo(editor.view)
		expect(editor.view.state.doc.toString()).toBe('Before selected after')
	})

	it('does not open the dialog or insert into a read-only note', () => {
		container = document.createElement('div')
		app = createApp({
			render: () => h(MarkdownEditor, { modelValue: 'Read only', readonly: true, onInsertLink: () => { throw new Error('Must not open') } }),
		})
		const root = app.mount(container)
		const editor = root.$.subTree.component.proxy
		expect(editor.openLinkDialog()).toBe(false)
		expect(editor.openLinkDialog('note')).toBe(false)
		editor.insertText('changed')
		expect(editor.view.state.doc.toString()).toBe('Read only')
	})
})
