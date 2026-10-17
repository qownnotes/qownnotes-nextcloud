/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { CONFLICTED_COPY_EMOJI, extractLeadingEmoji, isConflictedCopy, noteHeadline, noteListEntry } from '../../src/utils/noteEmoji.js'

describe('noteEmoji', () => {
	it('extracts leading emojis like QOwnNotes Desktop', () => {
		expect(extractLeadingEmoji('🛒 Shopping')).toBe('🛒')
		expect(extractLeadingEmoji('☀️ Holiday')).toBe('☀️')
		expect(extractLeadingEmoji('✅Done')).toBe('✅')
		expect(extractLeadingEmoji('👍🏽 Good')).toBe('👍🏽')
		expect(extractLeadingEmoji('👨‍👩‍👧 Family')).toBe('👨‍👩‍👧')
		expect(extractLeadingEmoji('🇦🇹 Austria')).toBe('🇦🇹')
		expect(extractLeadingEmoji('Shopping 🛒')).toBe('')
		expect(extractLeadingEmoji('1️⃣ First')).toBe('')
		expect(extractLeadingEmoji('')).toBe('')
	})

	it('finds the headline of the note', () => {
		expect(noteHeadline('# 🛒 Shopping\n\nMilk')).toBe('🛒 Shopping')
		expect(noteHeadline('## Title  \r\nText')).toBe('Title')
		expect(noteHeadline('---\ntags: a\n---\n# 🛒 Shopping\n')).toBe('🛒 Shopping')
		expect(noteHeadline('<!-- comment -->\n\n🛒 Shopping\n========\n')).toBe('🛒 Shopping')
		expect(noteHeadline(undefined)).toBe('')
	})

	it('detects sync conflict copies', () => {
		expect(isConflictedCopy({ title: 'TODO (conflicted copy 2026-07-10 070952)' })).toBe(true)
		expect(isConflictedCopy({ title: 'TODO' })).toBe(false)
	})

	it('builds the note list entry', () => {
		const note = { title: 'Shopping', content: '# 🛒 Shopping\n\nMilk' }
		expect(noteListEntry(note)).toEqual({ emoji: '🛒', name: 'Shopping' })
		expect(noteListEntry({ title: '🛒 Shopping', content: '# 🛒 Shopping' })).toEqual({ emoji: '🛒', name: 'Shopping' })
		expect(noteListEntry({ title: '🛒', content: '# 🛒' })).toEqual({ emoji: '🛒', name: '🛒' })
		expect(noteListEntry(note, false)).toEqual({ emoji: '', name: 'Shopping' })
		expect(noteListEntry({ title: 'Plain', content: '# Plain' })).toEqual({ emoji: '', name: 'Plain' })
		expect(noteListEntry({ title: 'TODO (conflicted copy 2026-07-10 070952)', content: '# 🛒 TODO' }))
			.toEqual({ emoji: CONFLICTED_COPY_EMOJI, name: 'TODO (conflicted copy 2026-07-10 070952)' })
	})
})
