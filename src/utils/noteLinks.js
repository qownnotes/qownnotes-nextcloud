/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { noteHeadings } from './markdown.js'
import { isRelativeLink, resolveRelativePath } from './mediaLinks.js'

// External links that can be opened in a new browser tab
const EXTERNAL_LINK = /^(?:https?|ftp|mailto):/iu

/**
 * What a link of a note points to
 *
 * @param {string} href the link
 * @return {{type: 'external'|'heading'|'file'|'none', path: string, fragment: string}} "file" links are
 *   relative links to notes or other files of the note folder
 */
export function parseLink(href) {
	const link = href.trim()
	const hashIndex = link.indexOf('#')
	const fragment = hashIndex === -1 ? '' : link.slice(hashIndex + 1)
	if (EXTERNAL_LINK.test(link)) {
		return { type: 'external', path: link, fragment: '' }
	}
	if (link.startsWith('#')) {
		return { type: 'heading', path: '', fragment }
	}
	if (isRelativeLink(link)) {
		return { type: 'file', path: hashIndex === -1 ? link : link.slice(0, hashIndex), fragment }
	}
	return { type: 'none', path: '', fragment: '' }
}

/**
 * The path of a note's file in the note folder, e.g. "Work/Project.md"
 *
 * @param {{category: string, title: string, internalPath?: string}} note the note
 * @return {string}
 */
export function notePath(note) {
	const fileName = note.internalPath?.split('/').pop() || `${note.title}.md`
	return note.category ? `${note.category}/${fileName}` : fileName
}

/**
 * The note a relative link points to, like in QOwnNotes Desktop the link is relative to the note's subfolder
 *
 * @param {Array<object>} notes all notes
 * @param {string} category subfolder of the note with the link
 * @param {string} link the link without fragment, e.g. "TODO.md" or "../Work/Project%20A.md"
 * @return {object|null}
 */
export function findLinkedNote(notes, category, link) {
	const path = resolveRelativePath(category, link)
	if (!path) {
		return null
	}
	const candidates = /\.[^/.]+$/u.test(path) ? [path] : [path, `${path}.md`, `${path}.txt`]
	for (const caseSensitive of [true, false]) {
		const normalize = (value) => (caseSensitive ? value : value.toLowerCase())
		const wanted = candidates.map(normalize)
		const note = notes.find((candidate) => wanted.includes(normalize(notePath(candidate))))
		if (note) {
			return note
		}
	}
	return null
}

/**
 * GitHub style anchor of a heading, e.g. "second-heading" for "Second heading!"
 *
 * @param {string} text the heading text
 * @return {string}
 */
export function headingSlug(text) {
	return text.trim().toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/gu, '-')
}

/**
 * The line of the heading a link fragment points to; the fragment is the URL-encoded heading text like in
 * QOwnNotes Desktop ("Heading%20text"), or its GitHub style anchor ("heading-text")
 *
 * @param {string} content the note text
 * @param {string} fragment the fragment of the link, without "#"
 * @return {number|null} the line (0-based), or null if there is no such heading
 */
export function findHeadingLine(content, fragment) {
	let text = fragment
	try {
		text = decodeURIComponent(fragment)
	} catch {
		// Fragments with invalid escape sequences are used as they are
	}
	text = text.trim()
	if (text === '') {
		return null
	}

	const headings = noteHeadings(content)
	const heading = headings.find((candidate) => candidate.text.trim().toLowerCase() === text.toLowerCase())
		?? headings.find((candidate) => headingSlug(candidate.text) === headingSlug(text))
	return heading?.line ?? null
}
