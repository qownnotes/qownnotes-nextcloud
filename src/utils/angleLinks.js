/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Links to files of the note folder in angle brackets like in QOwnNotes Desktop, e.g. "<Journal.md>",
// "<Work/My note.md#Heading>" or "<attachments/file.pdf>"; links with a scheme or an "@" are no file links
const ANGLE_LINK_SOURCE = String.raw`<((?:(?!\w+:\/\/)[^*<>@#\n])+\.\w+(?:#[^*<>@\n]*)?)>`
const ANGLE_LINK_START = new RegExp(`^${ANGLE_LINK_SOURCE}`, 'u')

/**
 * The angle bracket link at the start of a text, see ANGLE_LINK_SOURCE
 *
 * @param {string} text the text
 * @return {{length: number, href: string}|null} length of the link with the brackets, href without them
 */
export function matchAngleLink(text) {
	const match = ANGLE_LINK_START.exec(text)
	return match ? { length: match[0].length, href: match[1] } : null
}

/**
 * All angle bracket links of a text, see ANGLE_LINK_SOURCE
 *
 * @param {string} text the text
 * @return {Array<{from: number, to: number, href: string}>} from and to include the brackets
 */
export function findAngleLinks(text) {
	return [...text.matchAll(new RegExp(ANGLE_LINK_SOURCE, 'gu'))]
		.map((match) => ({ from: match.index, to: match.index + match[0].length, href: match[1] }))
}
