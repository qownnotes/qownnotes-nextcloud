/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { decodeNoteIds, encodeNoteIds } from '../../src/utils/dragAndDrop.js'
import { isNoteFile } from '../../src/utils/files.js'

const config = { notesPath: 'Notes', extensions: ['md', 'txt'] }

describe('files', () => {
	it('detects notes of the note folder', () => {
		expect(isNoteFile({ path: '/Notes/a.md', type: 'file' }, config)).toBe(true)
		expect(isNoteFile({ path: '/Notes/Work/A.TXT', type: 'file' }, config)).toBe(true)
		expect(isNoteFile({ path: '/Notes/Work', type: 'folder' }, config)).toBe(false)
		expect(isNoteFile({ path: '/Notes/notes.sqlite', type: 'file' }, config)).toBe(false)
		expect(isNoteFile({ path: '/Notes/.git/a.md', type: 'file' }, config)).toBe(false)
		expect(isNoteFile({ path: '/Notes/.hidden.md', type: 'file' }, config)).toBe(false)
		expect(isNoteFile({ path: '/NotesOther/a.md', type: 'file' }, config)).toBe(false)
		expect(isNoteFile({ path: '/a.md', type: 'file' }, config)).toBe(false)
		expect(isNoteFile({ path: '/a.md', type: 'file' }, { ...config, notesPath: '' })).toBe(true)
	})
})

describe('dragAndDrop', () => {
	it('encodes and decodes note IDs', () => {
		expect(decodeNoteIds(encodeNoteIds([1, 22, 3]))).toEqual([1, 22, 3])
		expect(decodeNoteIds('')).toEqual([])
		expect(decodeNoteIds('5,x,-1')).toEqual([5])
	})
})
