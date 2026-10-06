/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether a link points to a file of the note folder (relative link without scheme)
 *
 * @param {string} url the link
 * @return {boolean}
 */
export function isRelativeLink(url) {
	return url !== ''
		&& !/^[a-z][a-z0-9+.-]*:/iu.test(url)
		&& !url.startsWith('/')
		&& !url.startsWith('#')
		&& !url.startsWith('?')
}

/**
 * Resolves a link of a note, relative to the note's subfolder, to a path inside the note folder
 * (same rules as AttachmentService::resolveRelativePath)
 *
 * @param {string} subFolderPath subfolder of the note, '' for the root
 * @param {string} link the relative link
 * @return {string|null} the path relative to the note folder, or null if it is outside of the note folder
 */
export function resolveRelativePath(subFolderPath, link) {
	let path = link.split(/[?#]/u, 1)[0]
	try {
		path = decodeURIComponent(path)
	} catch {
		// Keep links with invalid escape sequences as they are
	}

	const segments = subFolderPath === '' ? [] : subFolderPath.split('/')
	for (const segment of path.replaceAll('\\', '/').split('/')) {
		if (segment === '' || segment === '.') {
			continue
		}
		if (segment === '..') {
			if (segments.length === 0) {
				return null
			}
			segments.pop()
			continue
		}
		segments.push(segment)
	}

	return segments.join('/')
}

/**
 * The URL of the attachment API that serves a file linked by a note
 *
 * @param {string} apiBase base URL of the API, e.g. "/index.php/apps/qownnotes/api/v1"
 * @param {number} noteId ID of the note
 * @param {string} link the relative link in the note
 * @return {string}
 */
export function attachmentUrl(apiBase, noteId, link) {
	return `${apiBase}/attachment/${noteId}?path=${encodeURIComponent(link.split(/[?#]/u, 1)[0])}`
}
