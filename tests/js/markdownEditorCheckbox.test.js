/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { history, undo } from '@codemirror/commands'
import { EditorSelection, EditorState } from '@codemirror/state'
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import MarkdownEditor from '../../src/components/MarkdownEditor.vue'
import { markdownEditorHighlighting, toggleTaskCheckbox } from '../../src/utils/markdownEditor.js'

/**
 * A command target that applies real CodeMirror transactions without a DOM.
 *
 * @param {string} doc Markdown text
 * @param {number} cursor cursor position
 * @param {boolean} readonly whether editing is disabled
 * @return {object} command target
 */
function editor(doc, cursor = doc.length, readonly = false) {
	const view = {
		state: EditorState.create({
			doc,
			selection: { anchor: cursor },
			extensions: [markdownEditorHighlighting(), history(), EditorState.readOnly.of(readonly), EditorState.allowMultipleSelections.of(true)],
		}),
		dispatch(spec) {
			view.state = spec.state || view.state.update(spec).state
		},
	}
	return view
}

describe('task checkbox command', () => {
	it.each(['- [ ] task', '* [ ] task', '+ [ ] task', '1. [ ] task', '2) [ ] task', '- parent\n  - [ ] nested', '> - [ ] quoted', '> > 1. [ ] nested quote'])('toggles %s in both directions and preserves the cursor', (doc) => {
		const view = editor(doc)
		expect(toggleTaskCheckbox(view)).toBe(true)
		expect(view.state.doc.toString()).toBe(doc.replace('[ ]', '[x]'))
		expect(view.state.selection.main.head).toBe(doc.length)
		expect(toggleTaskCheckbox(view)).toBe(true)
		expect(view.state.doc.toString()).toBe(doc)
	})

	it('unchecks uppercase X and supports undo', () => {
		const view = editor('- [X] done')
		expect(toggleTaskCheckbox(view)).toBe(true)
		expect(view.state.doc.toString()).toBe('- [ ] done')
		expect(undo(view)).toBe(true)
		expect(view.state.doc.toString()).toBe('- [X] done')
	})

	it.each(['plain [ ] text', '- ordinary item', '`- [ ] inline`', '```md\n- [ ] example\n```', '~~~\n- [ ] example\n~~~', '    - [ ] indented code', '<!--\n- [ ] comment\n-->'])('does not change non-task content: %s', (doc) => {
		const cursor = doc.includes('[ ]') ? doc.indexOf('[ ]') + 1 : doc.length
		const view = editor(doc, cursor)
		expect(toggleTaskCheckbox(view)).toBe(false)
		expect(view.state.doc.toString()).toBe(doc)
	})

	it('does not change read-only notes', () => {
		const view = editor('- [ ] task', 5, true)
		expect(toggleTaskCheckbox(view)).toBe(false)
		expect(view.state.doc.toString()).toBe('- [ ] task')
	})

	it('toggles each cursor line once and preserves selections', () => {
		const view = editor('- [ ] first\n- [x] second')
		view.dispatch({ selection: EditorSelection.create([EditorSelection.cursor(4), EditorSelection.cursor(10), EditorSelection.range(12, 22)]) })
		const selection = view.state.selection
		expect(toggleTaskCheckbox(view)).toBe(true)
		expect(view.state.doc.toString()).toBe('- [x] first\n- [ ] second')
		expect(view.state.selection.eq(selection)).toBe(true)
	})

	it('only changes the cursor line, not adjacent tasks', () => {
		const view = editor('- [ ] first\n- [ ] second', 0)
		expect(toggleTaskCheckbox(view)).toBe(true)
		expect(view.state.doc.toString()).toBe('- [x] first\n- [ ] second')
	})
})

let app
let container
afterEach(() => {
	app?.unmount()
	container?.remove()
})

describe('editor checkbox shortcut', () => {
	it.each([false, true])('handles Ctrl+Space with readonly=%s', (readonly) => {
		const updates = []
		container = document.createElement('div')
		document.body.append(container)
		app = createApp({
			render: () => h(MarkdownEditor, {
				modelValue: '- [ ] task',
				readonly,
				'onUpdate:modelValue': (value) => updates.push(value),
			}),
		})
		app.mount(container)
		const content = container.querySelector('.cm-content')
		content.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', code: 'Space', ctrlKey: true, bubbles: true, cancelable: true }))
		expect(updates).toEqual(readonly ? [] : ['- [x] task'])
		content.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', code: 'Space', ctrlKey: true, bubbles: true, cancelable: true }))
		expect(updates).toEqual(readonly ? [] : ['- [x] task', '- [ ] task'])
	})
})
