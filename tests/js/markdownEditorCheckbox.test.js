/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { history, undo } from '@codemirror/commands'
import { EditorSelection, EditorState } from '@codemirror/state'
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import MarkdownEditor from '../../src/components/MarkdownEditor.vue'
import { insertTaskAbove, markdownEditorHighlighting, toggleTaskCheckbox, toggleTaskList } from '../../src/utils/markdownEditor.js'

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

describe('insert task above command', () => {
	it.each(['- [ ] task', '* [x] task', '+ [X] task', '1. [ ] task', '2) [ ] task', '- parent\n  - [x] nested', '> - [ ] quoted', '> > 1. [ ] nested quote'])('inserts above %s before the marker or text', (doc) => {
		const lineStart = doc.lastIndexOf('\n') + 1
		const textStart = doc.indexOf(']', lineStart) + 2
		const prefix = doc.slice(lineStart, textStart).replace(/\[[xX]\]/, '[ ]')
		for (const cursor of [lineStart, doc.indexOf('[', lineStart), textStart]) {
			const view = editor(doc, cursor)
			expect(insertTaskAbove(view)).toBe(true)
			expect(view.state.doc.toString()).toBe(doc.slice(0, lineStart) + prefix + '\n' + doc.slice(lineStart))
			expect(view.state.selection.main.head).toBe(lineStart + prefix.length)
			expect(undo(view)).toBe(true)
			expect(view.state.doc.toString()).toBe(doc)
			expect(view.state.selection.main.head).toBe(cursor)
		}
	})

	it.each(['plain text', '- ordinary item', '- [ ] ', '```md\n- [ ] example\n```', '    - [ ] code', '<!--\n- [ ] comment\n-->'])('leaves non-tasks and empty tasks to normal Enter: %s', (doc) => {
		const view = editor(doc, Math.max(0, doc.indexOf('-')))
		expect(insertTaskAbove(view)).toBe(false)
		expect(view.state.doc.toString()).toBe(doc)
	})

	it('does not intercept Enter within task text or in read-only notes', () => {
		for (const view of [editor('- [ ] task', 7), editor('- [ ] task'), editor('- [ ] task', 0, true)]) {
			expect(insertTaskAbove(view)).toBe(false)
			expect(view.state.doc.toString()).toBe('- [ ] task')
		}
	})

	it('does not replace selected text', () => {
		const view = editor('- [ ] task')
		view.dispatch({ selection: EditorSelection.range(0, 6) })
		expect(insertTaskAbove(view)).toBe(false)
	})

	it('inserts above multiple cursor lines', () => {
		const view = editor('- [ ] first\n- [x] second')
		view.dispatch({ selection: EditorSelection.create([EditorSelection.cursor(0), EditorSelection.cursor(18)]) })
		expect(insertTaskAbove(view)).toBe(true)
		expect(view.state.doc.toString()).toBe('- [ ] \n- [ ] first\n- [ ] \n- [x] second')
		expect(view.state.selection.ranges.map((range) => range.head)).toEqual([6, 25])
	})
})

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

describe('task list formatting command', () => {
	it.each([
		['', '- [ ] ', '- '],
		['text', '- [ ] text', '- text'],
		['  text', '  - [ ] text', '  - text'],
		['- item', '- [ ] item', '- item'],
		['* item', '* [ ] item', '* item'],
		['+ item', '+ [ ] item', '+ item'],
		['2) item', '2) [ ] item', '2) item'],
		['12. item', '12. [ ] item', '12. item'],
		['- parent\n  - nested', '- parent\n  - [ ] nested', '- parent\n  - nested'],
		['> > - quoted', '> > - [ ] quoted', '> > - quoted'],
		['- ', '- [ ] ', '- '],
		['-', '- [ ] ', '- '],
	])('creates and toggles a task from %j', (doc, task, list) => {
		const view = editor(doc)
		expect(toggleTaskList(view)).toBe(true)
		expect(view.state.doc.toString()).toBe(task)
		expect(view.state.selection.main.head).toBe(task.length)
		expect(undo(view)).toBe(true)
		expect(view.state.doc.toString()).toBe(doc)
		expect(view.state.selection.main.head).toBe(doc.length)
		toggleTaskList(view)
		toggleTaskList(view)
		expect(view.state.doc.toString()).toBe(list)
		toggleTaskList(view)
		expect(view.state.doc.toString()).toBe(task)
	})

	it.each([' ', 'x', 'X'])('removes a checkbox marked %j without removing its text', (mark) => {
		const view = editor(`- [${mark}] item`, 8)
		toggleTaskList(view)
		expect(view.state.doc.toString()).toBe('- item')
		expect(view.state.selection.main.head).toBe(4)
	})

	it('positions the cursor after a new prefix at the start of a line', () => {
		const view = editor('text', 0)
		toggleTaskList(view)
		expect(view.state.selection.main.head).toBe(6)
	})

	it('formats selected lines once and excludes an unselected trailing line', () => {
		const view = editor('first\nsecond\nthird')
		view.dispatch({ selection: EditorSelection.range(0, 13) })
		toggleTaskList(view)
		expect(view.state.doc.toString()).toBe('- [ ] first\n- [ ] second\nthird')
		expect(view.state.sliceDoc(view.state.selection.main.from, view.state.selection.main.to)).toBe('first\n- [ ] second\n')
	})

	it('formats a line only once with multiple cursors', () => {
		const view = editor('first\nsecond')
		view.dispatch({ selection: EditorSelection.create([EditorSelection.cursor(0), EditorSelection.cursor(4), EditorSelection.cursor(8)]) })
		toggleTaskList(view)
		expect(view.state.doc.toString()).toBe('- [ ] first\n- [ ] second')
	})

	it('does not change read-only notes', () => {
		const view = editor('text', 0, true)
		expect(toggleTaskList(view)).toBe(false)
		expect(view.state.doc.toString()).toBe('text')
	})
})

let app
let container
afterEach(() => {
	app?.unmount()
	container?.remove()
})

describe('editor checkbox shortcut', () => {
	it.each(['shortcut', 'menu command'])('toggles task list formatting using the %s', (action) => {
		container = document.createElement('div')
		document.body.append(container)
		app = createApp(MarkdownEditor, { modelValue: '' })
		const component = app.mount(container)
		const run = () => {
			if (action === 'menu command') {
				component.toggleTaskList()
				expect(component.view.hasFocus).toBe(true)
			} else {
				const event = new KeyboardEvent('keydown', { key: 'l', code: 'KeyL', altKey: true, bubbles: true, cancelable: true })
				component.view.contentDOM.dispatchEvent(event)
				expect(event.defaultPrevented).toBe(true)
			}
		}
		run()
		expect(component.view.state.doc.toString()).toBe('- [ ] ')
		run()
		expect(component.view.state.doc.toString()).toBe('- ')
		run()
		expect(component.view.state.doc.toString()).toBe('- [ ] ')
	})

	it('does not change read-only notes with Alt+L or the menu command', () => {
		container = document.createElement('div')
		document.body.append(container)
		app = createApp(MarkdownEditor, { modelValue: 'text', readonly: true })
		const component = app.mount(container)
		component.view.contentDOM.dispatchEvent(new KeyboardEvent('keydown', { key: 'l', code: 'KeyL', altKey: true, bubbles: true, cancelable: true }))
		component.toggleTaskList()
		expect(component.view.state.doc.toString()).toBe('text')
	})

	it.each([
		[0, '- [ ] \n- [x] task'],
		[6, '- [ ] \n- [x] task'],
		[10, '- [x] task\n- [ ] '],
	])('handles Enter at cursor %s with Markdown keymap precedence', (cursor, expected) => {
		const updates = []
		container = document.createElement('div')
		document.body.append(container)
		app = createApp(MarkdownEditor, {
			modelValue: '- [x] task',
			'onUpdate:modelValue': (value) => updates.push(value),
		})
		const component = app.mount(container)
		component.view.dispatch({ selection: { anchor: cursor } })
		container.querySelector('.cm-content').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true }))
		expect(updates).toEqual([expected])
	})

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
