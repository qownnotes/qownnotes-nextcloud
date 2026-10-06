/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Flattens the tag tree of the tags API into a map of tag ID → tag with its name path
 *
 * @param {Array} tree the tag tree
 * @return {Map<number, {id: number, name: string, parentId: number, color: ?string, darkColor: ?string, noteCount: number, path: string[], childIds: number[]}>}
 */
export function flattenTagTree(tree) {
	const tags = new Map()
	const walk = (nodes, parentPath) => {
		for (const node of nodes) {
			const path = [...parentPath, node.name]
			tags.set(node.id, {
				id: node.id,
				name: node.name,
				parentId: node.parentId,
				priority: node.priority,
				color: node.color,
				darkColor: node.darkColor,
				noteCount: node.noteCount,
				path,
				childIds: node.children.map((child) => child.id),
			})
			walk(node.children, path)
		}
	}
	walk(tree, [])
	return tags
}

/**
 * The IDs of a tag and all its descendants
 *
 * @param {Map} tags flattened tags
 * @param {number} tagId the tag ID
 * @return {Set<number>}
 */
export function tagWithDescendants(tags, tagId) {
	const ids = new Set()
	const stack = [tagId]
	while (stack.length > 0) {
		const id = stack.pop()
		if (ids.has(id) || !tags.has(id)) {
			continue
		}
		ids.add(id)
		stack.push(...tags.get(id).childIds)
	}
	return ids
}

/**
 * Builds the map of note ID → set of tag IDs from the links of the tag-links API
 *
 * @param {Array<{tagId: number, noteId: ?number, stale: boolean}>} links the links
 * @return {Map<number, Set<number>>}
 */
export function noteTagMap(links) {
	const map = new Map()
	for (const link of links) {
		if (link.noteId === null || link.stale) {
			continue
		}
		if (!map.has(link.noteId)) {
			map.set(link.noteId, new Set())
		}
		map.get(link.noteId).add(link.tagId)
	}
	return map
}

/**
 * Whether a note matches the selected tags, like the tag filter of QOwnNotes Desktop
 *
 * @param {Set<number>|undefined} noteTagIds the tags of the note
 * @param {object} filter the filter
 * @param {number[]} filter.tagIds the selected tags
 * @param {boolean} filter.untagged only notes without tags
 * @param {string} filter.mode "and" or "or"
 * @param {boolean} filter.includeChildren whether the child tags of the selected tags match too
 * @param {Map} tags flattened tags
 * @return {boolean}
 */
export function matchesTagFilter(noteTagIds, { tagIds, untagged, mode, includeChildren }, tags) {
	const linked = noteTagIds ?? new Set()
	if (untagged) {
		return linked.size === 0
	}
	if (tagIds.length === 0) {
		return true
	}

	const matches = (tagId) => {
		const candidates = includeChildren ? tagWithDescendants(tags, tagId) : new Set([tagId])
		for (const id of candidates) {
			if (linked.has(id)) {
				return true
			}
		}
		return false
	}

	return mode === 'and' ? tagIds.every(matches) : tagIds.some(matches)
}

/**
 * The color of a tag for the current theme; the dark color falls back to the light color
 *
 * @param {{color: ?string, darkColor: ?string}} tag the tag
 * @param {boolean} dark whether a dark theme is used
 * @return {string|null}
 */
export function tagColor(tag, dark) {
	if (dark && tag.darkColor) {
		return tag.darkColor
	}
	return tag.color || null
}
