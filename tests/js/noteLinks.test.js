/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { EditorState } from '@codemirror/state'
import { describe, expect, it, vi } from 'vitest'
import { linkAt, markdownEditorHighlighting } from '../../src/utils/markdownEditor.js'
import { findHeadingLine, findLinkedNote, headingSlug, notePath, parseLink } from '../../src/utils/noteLinks.js'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))

const notes = [
	{ id: 1, title: 'TODO', category: '', internalPath: '/Notes/TODO.md' },
	{ id: 2, title: 'Project A', category: 'Work', internalPath: '/Notes/Work/Project A.md' },
	{ id: 3, title: 'Plain', category: 'Work', internalPath: '/Notes/Work/Plain.txt' },
]

describe('note links', () => {
	it('classifies links', () => {
		expect(parseLink('https://example.com/#x')).toEqual({ type: 'external', path: 'https://example.com/#x', fragment: '' })
		expect(parseLink('mailto:a@example.com').type).toBe('external')
		expect(parseLink('#second-heading')).toEqual({ type: 'heading', path: '', fragment: 'second-heading' })
		expect(parseLink('TODO.md#todo')).toEqual({ type: 'file', path: 'TODO.md', fragment: 'todo' })
		expect(parseLink('attachments/prompt.md')).toEqual({ type: 'file', path: 'attachments/prompt.md', fragment: '' })
		expect(parseLink('javascript:alert(1)').type).toBe('none')
		expect(parseLink('/absolute').type).toBe('none')
	})

	it('finds linked notes relative to the subfolder of the note', () => {
		expect(notePath(notes[1])).toBe('Work/Project A.md')
		expect(findLinkedNote(notes, '', 'TODO.md')?.id).toBe(1)
		expect(findLinkedNote(notes, '', 'todo.md')?.id).toBe(1)
		expect(findLinkedNote(notes, 'Work', '../TODO.md')?.id).toBe(1)
		expect(findLinkedNote(notes, 'Work', 'Project%20A.md')?.id).toBe(2)
		expect(findLinkedNote(notes, '', 'Work/Project A')?.id).toBe(2)
		expect(findLinkedNote(notes, 'Work', 'Plain.txt')?.id).toBe(3)
		expect(findLinkedNote(notes, 'Work', 'TODO.md')).toBeNull()
		expect(findLinkedNote(notes, '', 'attachments/prompt.md')).toBeNull()
		expect(findLinkedNote(notes, '', '../TODO.md')).toBeNull()
	})

	it('finds headings by their text or anchor', () => {
		const content = '# Title\n\n```\n# Not a heading\n```\n\n## Second heading!\n\nText\n\nTODO\n----\n'
		expect(headingSlug('Second heading!')).toBe('second-heading')
		expect(findHeadingLine(content, 'Second%20heading!')).toBe(6)
		expect(findHeadingLine(content, 'second-heading')).toBe(6)
		expect(findHeadingLine(content, 'todo')).toBe(10)
		expect(findHeadingLine(content, 'Not%20a%20heading')).toBeNull()
		expect(findHeadingLine(content, '')).toBeNull()
	})

	it('finds the link at a position of the editor', () => {
		const doc = 'See [TODO](TODO.md#todo), <https://example.org> and https://example.com, ![img](media/a.png) [ref][1]'
		const state = EditorState.create({ doc, extensions: markdownEditorHighlighting() })
		expect(linkAt(state, doc.indexOf('TODO'))).toBe('TODO.md#todo')
		expect(linkAt(state, doc.indexOf('TODO.md'))).toBe('TODO.md#todo')
		expect(linkAt(state, doc.indexOf('example.org'))).toBe('https://example.org')
		expect(linkAt(state, doc.indexOf('example.com'))).toBe('https://example.com')
		expect(linkAt(state, doc.indexOf('img'))).toBe('media/a.png')
		expect(linkAt(state, doc.indexOf('See'))).toBeNull()
		expect(linkAt(state, doc.indexOf('ref'))).toBeNull()
	})
})
