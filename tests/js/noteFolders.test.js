/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { candidateDescription, normalizeFolderPath } from '../../src/utils/noteFolders.js'

describe('normalizeFolderPath', () => {
	it.each([
		['Notes', 'Notes'],
		['/Notes/', 'Notes'],
		['Documents\\Notes', 'Documents/Notes'],
		['a/./b//c', 'a/b/c'],
		['../../Notizen', 'Notizen'],
		[' Notes / Work ', 'Notes/Work'],
		['', ''],
		[undefined, ''],
	])('normalizes %j like the server', (path, expected) => {
		expect(normalizeFolderPath(path)).toBe(expected)
	})
})

describe('candidateDescription', () => {
	const candidate = { noteCount: 0, noteCountCapped: false, hasTagDatabase: false, sources: [] }

	it('describes the notes and the sources of a folder', () => {
		expect(candidateDescription({ ...candidate, noteCount: 12, hasTagDatabase: true, sources: ['notesApp', 'defaultName', 'tagDatabase'] }))
			.toBe('12 notes · QOwnNotes note folder · Nextcloud Notes folder')
	})

	it('uses the singular for one note', () => {
		expect(candidateDescription({ ...candidate, noteCount: 1, sources: ['defaultName'] })).toBe('1 note')
	})

	it('marks capped note counts', () => {
		expect(candidateDescription({ ...candidate, noteCount: 1000, noteCountCapped: true })).toBe('At least 1000 notes')
	})

	it('omits the count of folders with only a note folder database', () => {
		expect(candidateDescription({ ...candidate, hasTagDatabase: true, sources: ['tagDatabase'] })).toBe('QOwnNotes note folder')
	})
})
