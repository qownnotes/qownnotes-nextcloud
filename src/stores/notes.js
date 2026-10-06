/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import * as api from '../api.js'
import { mergeNotes } from '../utils/notes.js'

/**
 * Thrown if a note was changed by another client since it was loaded
 */
export class NoteConflictError extends Error {
	constructor(current) {
		super('The note was changed in the meantime')
		this.current = current
	}
}

export const useNotesStore = defineStore('notes', {
	state: () => ({
		/** @type {Map<number, object>} */
		notes: new Map(),
		loaded: false,
		lastSync: 0,
	}),

	getters: {
		list: (state) => [...state.notes.values()],
		get: (state) => (id) => state.notes.get(id) ?? null,
	},

	actions: {
		async load() {
			const { notes, lastModified } = await api.fetchNotes(this.loaded ? this.lastSync : 0)
			this.notes = mergeNotes(this.notes, notes)
			this.lastSync = lastModified
			this.loaded = true
		},

		setNote(note) {
			this.notes.set(note.id, { ...(this.notes.get(note.id) ?? {}), ...note })
			return this.notes.get(note.id)
		},

		async reload(id) {
			return this.setNote(await api.fetchNote(id))
		},

		async create(category, title, content) {
			return this.setNote(await api.createNote({ category, title, content }))
		},

		/**
		 * Saves changes of a note; if `force` is false and the note was changed by another client,
		 * a NoteConflictError with the current note is thrown
		 *
		 * @param {number} id the note ID
		 * @param {object} changes content, title, category, favorite
		 * @param {boolean} force overwrite changes of other clients
		 */
		async update(id, changes, force = false) {
			const note = this.notes.get(id)
			try {
				return this.setNote(await api.updateNote(id, changes, force ? null : note?.etag))
			} catch (error) {
				if (api.errorStatus(error) === 412) {
					this.setNote(error.response.data)
					throw new NoteConflictError(error.response.data)
				}
				throw error
			}
		},

		async remove(id) {
			await api.deleteNote(id)
			this.notes.delete(id)
		},
	},
})
