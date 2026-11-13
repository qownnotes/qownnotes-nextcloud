/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { n, t } from '@nextcloud/l10n'

/**
 * Normalizes a note folder path like the server does (SettingsService::normalizePath)
 *
 * @param {string} path a path relative to the user's files
 * @return {string} the path without leading, trailing or duplicate slashes, "." and ".." resolved
 */
export function normalizeFolderPath(path) {
	const segments = []
	for (const segment of (path ?? '').replaceAll('\\', '/').split('/')) {
		const trimmed = segment.trim()
		if (trimmed === '' || trimmed === '.') {
			continue
		}
		if (trimmed === '..') {
			segments.pop()
			continue
		}
		segments.push(trimmed)
	}
	return segments.join('/')
}

/**
 * Describes a note folder candidate of `GET settings/note-folder-candidates`, e.g.
 * "12 notes · QOwnNotes note folder · Nextcloud Notes folder"
 *
 * @param {{noteCount: number, noteCountCapped: boolean, hasTagDatabase: boolean, sources: string[]}} candidate the candidate
 * @return {string}
 */
export function candidateDescription(candidate) {
	const count = candidate.noteCount
	const parts = []
	if (candidate.noteCountCapped) {
		parts.push(n('qownnotes', 'At least {count} note', 'At least {count} notes', count, { count }))
	} else if (count > 0) {
		parts.push(n('qownnotes', '{count} note', '{count} notes', count, { count }))
	}
	if (candidate.hasTagDatabase) {
		parts.push(t('qownnotes', 'QOwnNotes note folder'))
	}
	if (candidate.sources.includes('notesApp')) {
		parts.push(t('qownnotes', 'Nextcloud Notes folder'))
	}
	return parts.join(' · ')
}

// Like NoteFolderCandidateService::NOTE_EXTENSIONS
const NOTE_EXTENSIONS = ['md', 'txt', 'markdown', 'note', 'org']

/**
 * Whether a file name is a note, by the extensions the server counts as notes and the user's file suffix
 *
 * @param {string} name the file name
 * @param {string} fileSuffix the file extension of new notes, like ".md"
 * @return {boolean}
 */
export function isNoteFileName(name, fileSuffix = '') {
	const lower = (name ?? '').toLowerCase()
	if (lower === '' || lower.startsWith('.')) {
		return false
	}
	const suffix = (fileSuffix ?? '').toLowerCase().replace(/^\.+/u, '')
	const extension = lower.includes('.') ? lower.slice(lower.lastIndexOf('.') + 1) : ''
	return NOTE_EXTENSIONS.includes(extension) || (suffix !== '' && lower.endsWith('.' + suffix))
}
