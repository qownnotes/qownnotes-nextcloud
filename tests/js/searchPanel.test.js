/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { openSearchPanel, search, SearchQuery } from '@codemirror/search'
import { EditorState } from '@codemirror/state'
import { EditorView } from '@codemirror/view'
import { afterEach, describe, expect, it } from 'vitest'
import { countMatches, createSearchPanel } from '../../src/utils/searchPanel.js'

let view

afterEach(() => {
	view?.dom.parentElement?.remove()
	view?.destroy()
})

/**
 * @param {string} doc the text
 * @param {boolean} readonly whether the editor is read-only
 */
function openPanel(doc, readonly = false) {
	const parent = document.createElement('div')
	document.body.append(parent)
	view = new EditorView({
		parent,
		state: EditorState.create({ doc, extensions: [search({ top: true, createPanel: createSearchPanel }), EditorState.readOnly.of(readonly)] }),
	})
	openSearchPanel(view)
	return view.dom.querySelector('.qn-search')
}

/**
 * @param {HTMLInputElement} input the field
 * @param {string} value the new value
 */
function type(input, value) {
	input.value = value
	input.dispatchEvent(new Event('input'))
}

describe('search panel', () => {
	it('counts matches and the selected match', () => {
		const state = EditorState.create({ doc: 'a b a A', selection: { anchor: 4, head: 5 } })
		expect(countMatches(state, new SearchQuery({ search: 'a' }))).toEqual({ total: 3, current: 2, more: false })
		expect(countMatches(state, new SearchQuery({ search: 'a', caseSensitive: true }))).toEqual({ total: 2, current: 2, more: false })
		expect(countMatches(state, new SearchQuery({ search: '(', regexp: true }))).toEqual({ total: 0, current: 0, more: false })
	})

	it('finds matches with Enter and shows their position', () => {
		const panel = openPanel('one two one three one')
		const field = panel.querySelector('[name=search]')
		const count = panel.querySelector('.qn-search__count')
		type(field, 'one')
		expect(count.textContent).toBe('3 found')

		field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
		expect(count.textContent).toBe('1 of 3')
		field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
		expect(count.textContent).toBe('2 of 3')
		field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }))
		expect(count.textContent).toBe('1 of 3')

		type(field, 'four')
		expect(count.textContent).toBe('No results')
		expect(panel.classList.contains('qn-search--no-results')).toBe(true)
	})

	it('toggles search options and replaces matches', () => {
		const panel = openPanel('Cat cat cat')
		const replaceRow = panel.querySelector('.qn-search__row--replace')
		expect(replaceRow.hidden).toBe(true)
		panel.querySelector('.qn-search__expand').click()
		expect(replaceRow.hidden).toBe(false)

		type(panel.querySelector('[name=search]'), 'cat')
		const caseToggle = panel.querySelector('.qn-search__toggle')
		caseToggle.click()
		expect(caseToggle.getAttribute('aria-pressed')).toBe('true')
		expect(panel.querySelector('.qn-search__count').textContent).toBe('2 found')

		type(panel.querySelector('[name=replace]'), 'dog')
		panel.querySelectorAll('.qn-search__text-button')[1].click()
		expect(view.state.doc.toString()).toBe('Cat dog dog')
	})

	it('hides replacing in read-only notes', () => {
		const panel = openPanel('text', true)
		expect(panel.querySelector('.qn-search__expand').hidden).toBe(true)
		expect(panel.querySelector('.qn-search__row--replace').hidden).toBe(true)
	})
})
