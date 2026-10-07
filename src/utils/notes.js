/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether a note is inside the selected subfolder
 *
 * @param {string} category subfolder path of the note
 * @param {string|null} folder the selected subfolder ('' = root), null for all notes
 * @param {boolean} recursive whether notes of subfolders of the selected folder are included
 * @return {boolean}
 */
export function isInFolder(category, folder, recursive) {
	if (folder === null) {
		return true
	}
	if (category === folder) {
		return true
	}
	if (!recursive) {
		return false
	}
	return folder === '' || category.startsWith(folder + '/')
}

/**
 * Whether a note matches the search text; all words have to be in the title or the text
 *
 * @param {{title: string, content?: string}} note the note
 * @param {string} search the search text
 * @return {boolean}
 */
export function matchesSearch(note, search) {
	const words = search.toLocaleLowerCase().split(/\s+/u).filter((word) => word !== '')
	if (words.length === 0) {
		return true
	}

	const text = (note.title + '\n' + (note.content ?? '')).toLocaleLowerCase()
	return words.every((word) => text.includes(word))
}

/**
 * Sorts notes with favorites first, then by modification date (newest first) or title
 *
 * @param {Array} notes the notes
 * @param {string} order "modified" or "title"
 * @return {Array} a sorted copy
 */
export function sortNotes(notes, order) {
	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
	return [...notes].sort((a, b) => {
		if (a.favorite !== b.favorite) {
			return a.favorite ? -1 : 1
		}
		if (order === 'title') {
			return collator.compare(a.title, b.title) || b.modified - a.modified
		}
		return b.modified - a.modified || collator.compare(a.title, b.title)
	})
}

/**
 * Merges a (possibly pruned) note list of the Notes API into the known notes; pruned notes only contain
 * their ID and keep their known data, notes missing in the list were deleted
 *
 * @param {Map<number, object>} known the known notes
 * @param {Array<object>} received the notes from the API
 * @return {Map<number, object>} the new note map
 */
export function mergeNotes(known, received) {
	const merged = new Map()
	for (const note of received) {
		if (Object.keys(note).length === 1) {
			if (known.has(note.id)) {
				merged.set(note.id, known.get(note.id))
			}
			continue
		}
		merged.set(note.id, { ...(known.get(note.id) ?? {}), ...note })
	}
	return merged
}

/**
 * Flattens the subfolder tree of the subfolders API into a list of folder paths
 *
 * @param {object} tree the root node of the subfolder tree
 * @return {string[]}
 */
export function folderPaths(tree) {
	const paths = []
	const walk = (node) => {
		paths.push(node.path)
		node.children.forEach(walk)
	}
	if (tree) {
		walk(tree)
	}
	return paths
}

/**
 * Joins a parent folder path and a folder name
 *
 * @param {string} parent the parent path, '' for the root
 * @param {string} name the name
 * @return {string}
 */
export function joinPath(parent, name) {
	return parent === '' ? name : `${parent}/${name}`
}

/**
 * The parent path of a folder path
 *
 * @param {string} path the path
 * @return {string}
 */
export function parentPath(path) {
	const index = path.lastIndexOf('/')
	return index === -1 ? '' : path.slice(0, index)
}

/**
 * The last segment of a folder path
 *
 * @param {string} path the path
 * @return {string}
 */
export function baseName(path) {
	return path.slice(path.lastIndexOf('/') + 1)
}

/**
 * The notes between two notes of the note list (both included), for selecting with Shift+click
 *
 * @param {number[]} orderedIds the IDs of the note list, in their displayed order
 * @param {number|null} anchorId the note that was selected first
 * @param {number} targetId the clicked note
 * @return {number[]}
 */
export function rangeSelection(orderedIds, anchorId, targetId) {
	const target = orderedIds.indexOf(targetId)
	const anchor = anchorId === null ? -1 : orderedIds.indexOf(anchorId)
	if (target === -1) {
		return []
	}
	if (anchor === -1) {
		return [targetId]
	}
	return orderedIds.slice(Math.min(anchor, target), Math.max(anchor, target) + 1)
}
