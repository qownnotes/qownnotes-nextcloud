/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Same rules as lib/Service/NoteTitle.php (QOwnNotes Desktop: Note::cleanupFileName)

export const DEFAULT_TITLE = 'Note'
export const MAX_LENGTH = 200

/**
 * Makes a title usable as file name
 *
 * @param {string} title the title
 * @return {string}
 */
export function sanitizeTitle(title) {
	let result = String(title)
		.replace(/[/\\:]/gu, '')
		// eslint-disable-next-line no-control-regex
		.replace(/[<>"|?*\x00-\x1F\x7F]/gu, ' ')
		.replace(/\s+/gu, ' ')
		.trim()
		.replace(/^[. ]+/u, '')
	result = Array.from(result).slice(0, MAX_LENGTH).join('')
	return result.replace(/[. ]+$/u, '')
}

/**
 * Derives the note name from the first line of the note text, like QOwnNotes Desktop
 *
 * @param {string} content the note text
 * @return {string}
 */
export function titleFromContent(content) {
	const firstLine = String(content).split(/\r\n|\r|\n/u, 1)[0] ?? ''
	const title = sanitizeTitle(firstLine.trim().replace(/^#\s/u, ''))
	return title === '' ? DEFAULT_TITLE : title
}

/**
 * The name of a new note, like in QOwnNotes Desktop, e.g. "Note 2026-10-06 14h05s09"
 *
 * @param {Date} date the creation time
 * @return {string}
 */
export function defaultNoteTitle(date) {
	const pad = (value) => String(value).padStart(2, '0')
	return `Note ${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} `
		+ `${pad(date.getHours())}h${pad(date.getMinutes())}s${pad(date.getSeconds())}`
}

/**
 * Creates the headline of a new note, like Note::createNoteHeader in QOwnNotes Desktop
 *
 * @param {string} title the note title
 * @param {string} style "atx" (# Title) or "setext" (underlined title)
 * @return {string}
 */
export function createNoteHeader(title, style = 'atx') {
	const trimmed = String(title).trim()
	if (style !== 'setext') {
		return `# ${trimmed}\n\n`
	}

	return `${trimmed}\n${'='.repeat(Math.min(Array.from(trimmed).length, 40))}\n\n`
}
