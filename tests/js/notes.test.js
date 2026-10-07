/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { baseName, folderPaths, isInFolder, joinPath, matchesSearch, mergeNotes, parentPath, rangeSelection, sortNotes } from '../../src/utils/notes.js'

describe('notes', () => {
	it('filters notes by subfolder', () => {
		expect(isInFolder('Work', null, false)).toBe(true)
		expect(isInFolder('', '', false)).toBe(true)
		expect(isInFolder('Work', '', false)).toBe(false)
		expect(isInFolder('Work', '', true)).toBe(true)
		expect(isInFolder('Work/Project', 'Work', true)).toBe(true)
		expect(isInFolder('Work/Project', 'Work', false)).toBe(false)
		// Prefix matches only whole folder names
		expect(isInFolder('Workplace', 'Work', true)).toBe(false)
	})

	it('searches title and text for all words', () => {
		const note = { title: 'Shopping list', content: 'Milk and Bread' }
		expect(matchesSearch(note, '')).toBe(true)
		expect(matchesSearch(note, 'shopping bread')).toBe(true)
		expect(matchesSearch(note, 'shopping cheese')).toBe(false)
		expect(matchesSearch({ title: 'Without content' }, 'without')).toBe(true)
	})

	it('sorts favorites first, then by date or name', () => {
		const notes = [
			{ id: 1, title: 'b', modified: 10, favorite: false },
			{ id: 2, title: 'a', modified: 20, favorite: false },
			{ id: 3, title: 'c', modified: 5, favorite: true },
			{ id: 4, title: 'Note 10', modified: 1, favorite: false },
			{ id: 5, title: 'Note 9', modified: 1, favorite: false },
		]
		expect(sortNotes(notes, 'modified').map((note) => note.id)).toEqual([3, 2, 1, 5, 4])
		expect(sortNotes(notes, 'title').map((note) => note.id)).toEqual([3, 2, 1, 5, 4])
		expect(notes[0].id).toBe(1)
	})

	it('merges pruned note lists', () => {
		const known = new Map([
			[1, { id: 1, title: 'One', content: 'old' }],
			[2, { id: 2, title: 'Two', content: 'two' }],
			[3, { id: 3, title: 'Deleted' }],
		])
		const merged = mergeNotes(known, [{ id: 1, title: 'One', content: 'new' }, { id: 2 }, { id: 4, title: 'New' }, { id: 5 }])
		expect([...merged.keys()]).toEqual([1, 2, 4])
		expect(merged.get(1).content).toBe('new')
		expect(merged.get(2).content).toBe('two')
	})

	it('handles folder paths', () => {
		const tree = { path: '', children: [{ path: 'A', children: [{ path: 'A/B', children: [] }] }, { path: 'C', children: [] }] }
		expect(folderPaths(tree)).toEqual(['', 'A', 'A/B', 'C'])
		expect(folderPaths(null)).toEqual([])
		expect(joinPath('', 'A')).toBe('A')
		expect(joinPath('A', 'B')).toBe('A/B')
		expect(parentPath('A/B')).toBe('A')
		expect(parentPath('A')).toBe('')
		expect(baseName('A/B')).toBe('B')
	})
})

describe('rangeSelection', () => {
	it('selects the notes between anchor and target', () => {
		expect(rangeSelection([1, 2, 3, 4], 2, 4)).toEqual([2, 3, 4])
		expect(rangeSelection([1, 2, 3, 4], 4, 2)).toEqual([2, 3, 4])
		expect(rangeSelection([1, 2, 3, 4], null, 3)).toEqual([3])
		expect(rangeSelection([1, 2, 3, 4], 9, 3)).toEqual([3])
		expect(rangeSelection([1, 2, 3, 4], 1, 9)).toEqual([])
	})
})
