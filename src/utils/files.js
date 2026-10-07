/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether a file of the Files app is a note of the note folder, so it can be opened in the web interface
 *
 * @param {object} file the file
 * @param {string} file.path path in the user's files, e.g. "/Notes/Work/Meeting.md"
 * @param {string} file.type "file" or "folder"
 * @param {object} config the note folder settings of the user
 * @param {string} config.notesPath path of the note folder, without leading and trailing slashes
 * @param {string[]} config.extensions note file extensions without dot, lowercase
 * @return {boolean}
 */
export function isNoteFile({ path, type }, { notesPath, extensions }) {
	if (type !== 'file') {
		return false
	}

	const relative = path.replace(/^\/+/u, '')
	const prefix = notesPath === '' ? '' : notesPath + '/'
	if (!relative.startsWith(prefix)) {
		return false
	}

	const segments = relative.slice(prefix.length).split('/')
	const name = segments.at(-1)
	// Hidden files and files in hidden folders are never notes
	if (segments.some((segment) => segment.startsWith('.'))) {
		return false
	}

	const dot = name.lastIndexOf('.')
	return dot > 0 && extensions.includes(name.slice(dot + 1).toLowerCase())
}
