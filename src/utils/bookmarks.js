/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Defaults of the QOwnNotes Desktop web companion settings "bookmarksTag" and "bookmarksNoteName"
export const BOOKMARKS_TAG = 'bookmarks'
export const BOOKMARKS_NOTE_NAME = 'Bookmarks'

// Same patterns as Bookmark::parseBookmarks of QOwnNotes Desktop and Bookmarks.kt of QOwnNotes Android
const BOOKMARK_PATTERN = /[-*]\s+\[([^[\]]+?)\]\(([\w-]+:\/\/.+?)\)(.*)$/gmu
const TAG_PATTERN = /#([^\s#]+)/gu

/**
 * @typedef {object} Bookmark
 * @property {string} url the URL, which identifies the bookmark
 * @property {string} name the link text
 * @property {string[]} tags the tags without "#"
 * @property {string} description the remaining text of the list item
 */

/**
 * @param {string} text the text
 * @return {string}
 */
function escapeRegExp(text) {
	return text.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&')
}

/**
 * Merges a bookmark into a list; bookmarks with the same URL are combined like in QOwnNotes Desktop
 *
 * @param {Bookmark[]} bookmarks the list, changed in place
 * @param {Bookmark} bookmark the bookmark
 */
function mergeBookmark(bookmarks, bookmark) {
	const existing = bookmarks.find((other) => other.url === bookmark.url)
	if (!existing) {
		bookmarks.push(bookmark)
		return
	}
	if (bookmark.name.length > existing.name.length) {
		existing.name = bookmark.name
	}
	existing.tags = [...new Set([...existing.tags, ...bookmark.tags])].sort()
	if (bookmark.description !== '' && !existing.description.includes(bookmark.description)) {
		existing.description = existing.description === '' || bookmark.description.includes(existing.description)
			? bookmark.description
			: `${existing.description}, ${bookmark.description}`
	}
}

/**
 * The bookmarks of a note text: list items like "- [Name](https://example.com) #tag description"
 *
 * @param {string} markdown the note text
 * @return {Bookmark[]}
 */
export function parseBookmarks(markdown) {
	const bookmarks = []
	for (const match of markdown.matchAll(BOOKMARK_PATTERN)) {
		let additionalText = match[3]
		const tags = []
		for (const tagMatch of match[3].matchAll(TAG_PATTERN)) {
			const tag = tagMatch[1].trim()
			if (!tags.includes(tag)) {
				tags.push(tag)
				additionalText = additionalText.replace(new RegExp(`#${escapeRegExp(tag)}\\b`, 'gu'), '')
			}
		}
		mergeBookmark(bookmarks, { url: match[2], name: match[1], tags, description: additionalText.trim() })
	}
	return bookmarks
}

/**
 * The bookmarks of several notes; each bookmark knows the IDs of the notes that contain it
 *
 * @param {Array<{id: number, content: string}>} notes the bookmark notes
 * @return {Array<Bookmark & {noteIds: number[]}>}
 */
export function collectBookmarks(notes) {
	const bookmarks = []
	for (const note of notes) {
		for (const bookmark of parseBookmarks(note.content ?? '')) {
			const existing = bookmarks.find((other) => other.url === bookmark.url)
			mergeBookmark(bookmarks, { ...bookmark, noteIds: [] })
			;(existing ?? bookmarks.at(-1)).noteIds.push(note.id)
		}
	}
	return bookmarks
}

/**
 * Whether a note is a bookmark note, like in QOwnNotes Desktop: it has the tag "bookmarks" or is named "Bookmarks"
 *
 * @param {{title: string}} note the note
 * @param {Set<number>|undefined} noteTagIds the tags of the note
 * @param {Set<number>} bookmarkTagIds the IDs of the tags named "bookmarks"
 * @return {boolean}
 */
export function isBookmarkNote(note, noteTagIds, bookmarkTagIds) {
	if (note.title === BOOKMARKS_NOTE_NAME) {
		return true
	}
	return [...(noteTagIds ?? [])].some((tagId) => bookmarkTagIds.has(tagId))
}

/**
 * The bookmarks that match the search text and all selected tags, sorted by name
 *
 * @param {Bookmark[]} bookmarks the bookmarks
 * @param {string} search the search text; all words have to be in the name, URL, description or tags
 * @param {string[]} selectedTags the selected tags
 * @return {Bookmark[]}
 */
export function filterBookmarks(bookmarks, search, selectedTags = []) {
	const words = search.toLocaleLowerCase().split(/\s+/u).filter((word) => word !== '')
	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
	return bookmarks
		.filter((bookmark) => {
			const text = [bookmark.name, bookmark.url, bookmark.description, ...bookmark.tags.map((tag) => '#' + tag)]
				.join('\n').toLocaleLowerCase()
			return words.every((word) => text.includes(word)) && selectedTags.every((tag) => bookmark.tags.includes(tag))
		})
		.sort((a, b) => collator.compare(a.name || a.url, b.name || b.url) || collator.compare(a.url, b.url))
}

/**
 * Splits the tags field of the edit form, e.g. "#work news" → ["work", "news"]
 *
 * @param {string} text the tags, separated by spaces
 * @return {string[]}
 */
export function parseTagsInput(text) {
	return [...new Set(text.split(/\s+/u).map((tag) => tag.replace(/^#+/u, '')).filter((tag) => tag !== ''))]
}

/**
 * The Markdown list item of a bookmark, like QOwnNotes Android writes it
 *
 * @param {Bookmark} bookmark the bookmark
 * @return {string}
 */
export function serializeBookmark({ url, name, tags, description }) {
	let line = `- [${name}](${url})`
	for (const tag of tags) {
		line += ` #${tag}`
	}
	if (description.trim() !== '') {
		line += ` ${description.trim()}`
	}
	return line
}

/**
 * Checks an edited bookmark; it has to be read back unchanged by QOwnNotes Desktop and Android
 *
 * @param {Bookmark} bookmark the bookmark
 * @return {string|null} the problem ("name", "url", "tags" or "description"), or null if it is valid
 */
export function bookmarkProblem({ url, name, tags, description }) {
	// eslint-disable-next-line no-control-regex
	const control = /[\u0000-\u001f\u007f-\u009f]/u
	if (name.trim() === '' || control.test(name) || /[[\]]/u.test(name) || name !== name.trim()) {
		return 'name'
	}
	if (url === '' || control.test(url) || /\s/u.test(url) || !/^[\w-]+:\/\/./u.test(url)) {
		return 'url'
	}
	if (tags.some((tag) => tag === '' || /[\s#]/u.test(tag) || control.test(tag))) {
		return 'tags'
	}
	if (control.test(description) || /#[^\s#]/u.test(description)) {
		return 'description'
	}

	const [parsed] = parseBookmarks(serializeBookmark({ url, name, tags, description }))
	const same = parsed && parsed.url === url && parsed.name === name && parsed.description === description.trim()
		&& parsed.tags.join(' ') === tags.join(' ')
	return same ? null : 'name'
}

/**
 * Replaces all list items of a bookmark with the edited bookmark; the list character and indentation are kept
 *
 * @param {string} markdown the note text
 * @param {string} url the URL of the bookmark
 * @param {Bookmark} replacement the edited bookmark
 * @return {string}
 */
export function editBookmark(markdown, url, replacement) {
	const line = serializeBookmark(replacement)
	return markdown.replace(BOOKMARK_PATTERN, (match, name, matchUrl) => (matchUrl === url ? match[0] + line.slice(1) : match))
}

/**
 * Removes all list items of a bookmark; lines that only contain the bookmark are removed completely
 *
 * @param {string} markdown the note text
 * @param {string} url the URL of the bookmark
 * @return {string}
 */
export function removeBookmark(markdown, url) {
	const matches = [...markdown.matchAll(BOOKMARK_PATTERN)].filter((match) => match[2] === url)
	let result = markdown
	for (const match of matches.reverse()) {
		const lineStart = markdown.lastIndexOf('\n', match.index - 1) + 1
		const standalone = markdown.slice(lineStart, match.index).trim() === ''
		const start = standalone ? lineStart : match.index
		let end = match.index + match[0].length
		if (standalone && markdown[end] === '\r') {
			end++
		}
		if (standalone && markdown[end] === '\n') {
			end++
		}
		result = result.slice(0, start) + result.slice(end)
	}
	return result
}

/**
 * Whether a URL can be opened in a new browser tab
 *
 * @param {string} url the URL
 * @return {boolean}
 */
export function isOpenableUrl(url) {
	return /^https?:\/\/[^/\s]/iu.test(url)
}
