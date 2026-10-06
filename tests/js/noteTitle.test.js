/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { createNoteHeader, defaultNoteTitle, sanitizeTitle, titleFromContent } from '../../src/utils/noteTitle.js'

// The same cases as tests/unit/Service/NoteTitleTest.php, so the web interface and the server agree
describe('noteTitle', () => {
	it('sanitizes titles like the server', () => {
		expect(sanitizeTitle('  My   note  ')).toBe('My note')
		expect(sanitizeTitle('a/b\\c:d')).toBe('abcd')
		expect(sanitizeTitle('What? <yes> "no" | *')).toBe('What yes no')
		expect(sanitizeTitle('...hidden')).toBe('hidden')
		expect(sanitizeTitle('trailing...')).toBe('trailing')
		expect(Array.from(sanitizeTitle('ä'.repeat(300)))).toHaveLength(200)
	})

	it('derives the title from the first line', () => {
		expect(titleFromContent('# Meeting\n\nText')).toBe('Meeting')
		expect(titleFromContent('Meeting\n=======\n')).toBe('Meeting')
		expect(titleFromContent('#Hashtag')).toBe('#Hashtag')
		expect(titleFromContent('')).toBe('Note')
		expect(titleFromContent('\nSecond line')).toBe('Note')
		expect(titleFromContent('# a/b\r\nText')).toBe('ab')
	})

	it('creates note headers', () => {
		expect(createNoteHeader('Title')).toBe('# Title\n\n')
		expect(createNoteHeader('Title', 'setext')).toBe('Title\n=====\n\n')
	})

	it('creates the default note name like QOwnNotes Desktop', () => {
		expect(defaultNoteTitle(new Date(2026, 9, 6, 9, 5, 3))).toBe('Note 2026-10-06 09h05s03')
	})
})
