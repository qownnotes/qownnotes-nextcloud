/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { notePath } from './noteLinks.js'

/**
 * Desktop-compatible relative link, preserving the actual file extension.
 *
 * @param {string} category source subfolder
 * @param {object} note target note
 * @return {string}
 */
export function relativeNoteLink(category, note) {
	const source = category ? category.split('/') : []
	const target = notePath(note).split('/')
	while (source.length && source[0] === target[0]) {
		source.shift()
		target.shift()
	}
	return [...source.map(() => '..'), ...target].map(encodeURIComponent).join('/')
}

/**
 * Escapes plain dialog text and destinations so they form a single Markdown link.
 *
 * @param {string} title link text
 * @param {string} href destination (already URL encoded for note links)
 * @return {string}
 */
export function markdownLink(title, href) {
	const label = (title.trim() || href).replace(/\s+/gu, ' ').replace(/[\\`*_[\]<>&|]/gu, '\\$&')
	const destination = href.replace(/[\s<>\\()]/gu, (character) => encodeURIComponent(character).replace(/\(/gu, '%28').replace(/\)/gu, '%29'))
	return `[${label}](${destination})`
}
