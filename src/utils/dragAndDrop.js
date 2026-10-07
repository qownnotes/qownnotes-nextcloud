/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Drag data of notes: the note IDs, separated by commas */
export const DRAG_TYPE_NOTE = 'application/x-qownnotes-note'

/** Drag data of a subfolder: the subfolder path */
export const DRAG_TYPE_FOLDER = 'application/x-qownnotes-folder'

/** Drag data of a tag: the tag ID */
export const DRAG_TYPE_TAG = 'application/x-qownnotes-tag'

/**
 * @param {number[]} noteIds the dragged notes
 * @return {string}
 */
export function encodeNoteIds(noteIds) {
	return noteIds.join(',')
}

/**
 * @param {string} data the drag data
 * @return {number[]} the note IDs, empty if there are none
 */
export function decodeNoteIds(data) {
	return data.split(',').map(Number).filter((id) => Number.isInteger(id) && id > 0)
}
