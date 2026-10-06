/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { flattenTagTree, matchesTagFilter, noteTagMap, tagColor, tagWithDescendants } from '../../src/utils/tags.js'

const tree = [
	{
		id: 1,
		name: 'Work',
		parentId: 0,
		color: '#ff0000',
		darkColor: null,
		noteCount: 1,
		children: [
			{ id: 2, name: 'Project', parentId: 1, color: null, darkColor: '#00ff00', noteCount: 1, children: [] },
		],
	},
	{ id: 3, name: 'Home', parentId: 0, color: null, darkColor: null, noteCount: 1, children: [] },
]

describe('tags', () => {
	const tags = flattenTagTree(tree)

	it('flattens the tag tree with name paths', () => {
		expect([...tags.keys()]).toEqual([1, 2, 3])
		expect(tags.get(2).path).toEqual(['Work', 'Project'])
		expect(tags.get(1).childIds).toEqual([2])
		expect([...tagWithDescendants(tags, 1)].sort()).toEqual([1, 2])
		expect([...tagWithDescendants(tags, 99)]).toEqual([])
	})

	it('maps notes to tags without stale links', () => {
		const map = noteTagMap([
			{ tagId: 1, noteId: 10, stale: false },
			{ tagId: 2, noteId: 10, stale: false },
			{ tagId: 3, noteId: 11, stale: true },
			{ tagId: 3, noteId: null, stale: false },
		])
		expect([...map.keys()]).toEqual([10])
		expect([...map.get(10)]).toEqual([1, 2])
	})

	it('filters like QOwnNotes Desktop', () => {
		const filter = { tagIds: [], untagged: false, mode: 'or', includeChildren: true }
		expect(matchesTagFilter(new Set([2]), filter, tags)).toBe(true)
		expect(matchesTagFilter(new Set([2]), { ...filter, tagIds: [1] }, tags)).toBe(true)
		expect(matchesTagFilter(new Set([2]), { ...filter, tagIds: [1], includeChildren: false }, tags)).toBe(false)
		expect(matchesTagFilter(new Set([2]), { ...filter, tagIds: [1, 3] }, tags)).toBe(true)
		expect(matchesTagFilter(new Set([2]), { ...filter, tagIds: [1, 3], mode: 'and' }, tags)).toBe(false)
		expect(matchesTagFilter(new Set([2, 3]), { ...filter, tagIds: [1, 3], mode: 'and' }, tags)).toBe(true)
		expect(matchesTagFilter(undefined, { ...filter, untagged: true }, tags)).toBe(true)
		expect(matchesTagFilter(new Set([3]), { ...filter, untagged: true }, tags)).toBe(false)
	})

	it('uses the dark color in dark themes', () => {
		expect(tagColor(tags.get(1), false)).toBe('#ff0000')
		expect(tagColor(tags.get(1), true)).toBe('#ff0000')
		expect(tagColor(tags.get(2), true)).toBe('#00ff00')
		expect(tagColor(tags.get(2), false)).toBeNull()
	})
})
