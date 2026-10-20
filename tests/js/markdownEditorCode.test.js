/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { undo } from '@codemirror/commands'
import { EditorSelection } from '@codemirror/state'
import { afterEach, describe, expect, it } from 'vitest'
import { createApp } from 'vue'
import MarkdownEditor from '../../src/components/MarkdownEditor.vue'

let app
let container

afterEach(() => {
	app?.unmount()
	container?.remove()
})

describe.each(['shortcut', 'menu command'])('editor code %s', (action) => {
	it.each([
		['', 0, 0, '```\n\n```', 4],
		['text', 0, 0, '```\n\n```\ntext', 4],
		['before\nafter', 7, 7, 'before\n```\n\n```\nafter', 11],
		['before after', 7, 7, 'before ``after', 8],
		['text', 4, 4, 'text``', 5],
		['  text', 2, 2, '  ``text', 3],
		['before selected after', 7, 15, 'before `selected` after', 17],
		['text', 0, 4, '`text`', 6],
		['one\ntwo', 0, 7, '```\none\ntwo\n```', 15],
		['one\ntwo\nnext', 0, 8, '```\none\ntwo\n```\nnext', 16],
		['before one\ntwo after', 7, 14, 'before \n```\none\ntwo\n```\n after', 24],
	])('formats %j at %s–%s in one undoable edit', (doc, anchor, head, expected, cursor) => {
		const updates = []
		container = document.createElement('div')
		document.body.append(container)
		app = createApp(MarkdownEditor, {
			modelValue: doc,
			'onUpdate:modelValue': (value) => updates.push(value),
		})
		const editor = app.mount(container)
		editor.view.dispatch({ selection: EditorSelection.range(anchor, head) })
		const event = new KeyboardEvent('keydown', { key: 'C', code: 'KeyC', keyCode: 67, ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true })
		if (action === 'shortcut') {
			editor.view.contentDOM.dispatchEvent(event)
			expect(event.defaultPrevented).toBe(true)
		} else {
			editor.insertCode()
			expect(editor.view.hasFocus).toBe(true)
		}
		expect(updates).toEqual([expected])
		expect(editor.view.state.selection.main.head).toBe(cursor)
		expect(undo(editor.view)).toBe(true)
		expect(editor.view.state.doc.toString()).toBe(doc)
		expect(editor.view.state.selection.main.anchor).toBe(anchor)
		expect(editor.view.state.selection.main.head).toBe(head)
	})

	it('does not modify read-only notes', () => {
		container = document.createElement('div')
		document.body.append(container)
		app = createApp(MarkdownEditor, { modelValue: 'Read only', readonly: true })
		const editor = app.mount(container)
		editor.view.contentDOM.dispatchEvent(new KeyboardEvent('keydown', { key: 'C', code: 'KeyC', keyCode: 67, ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true }))
		editor.insertCode()
		expect(editor.view.state.doc.toString()).toBe('Read only')
	})
})
