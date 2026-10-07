/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { diffTexts } from '../../src/utils/diff.js'

describe('diff', () => {
	it('finds changed words', () => {
		expect(diffTexts('the quick fox', 'the slow fox')).toEqual([
			{ type: 'equal', text: 'the ' },
			{ type: 'delete', text: 'quick' },
			{ type: 'insert', text: 'slow' },
			{ type: 'equal', text: ' fox' },
		])
	})

	it('handles insertions, deletions and equal texts', () => {
		expect(diffTexts('', 'new')).toEqual([{ type: 'insert', text: 'new' }])
		expect(diffTexts('old', '')).toEqual([{ type: 'delete', text: 'old' }])
		expect(diffTexts('same', 'same')).toEqual([{ type: 'equal', text: 'same' }])
		expect(diffTexts('a c', 'a b c')).toEqual([
			{ type: 'equal', text: 'a ' },
			{ type: 'insert', text: 'b ' },
			{ type: 'equal', text: 'c' },
		])
	})

	it('falls back to lines for long texts', () => {
		const long = 'word '.repeat(1500)
		const parts = diffTexts(long + '\nold line\n', long + '\nnew line\n')
		expect(parts.map((part) => part.type)).toEqual(['equal', 'delete', 'insert'])
		expect(parts[1].text).toBe('old line\n')
	})
})

describe('diff cleanup', () => {
	it('does not interleave changed words', () => {
		expect(diffTexts('original and mine', 'changed elsewhere')).toEqual([
			{ type: 'delete', text: 'original and mine' },
			{ type: 'insert', text: 'changed elsewhere' },
		])
		expect(diffTexts('keep a b keep', 'keep c d keep')).toEqual([
			{ type: 'equal', text: 'keep ' },
			{ type: 'delete', text: 'a b' },
			{ type: 'insert', text: 'c d' },
			{ type: 'equal', text: ' keep' },
		])
	})
})
