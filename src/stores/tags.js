/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import * as api from '../api.js'
import { flattenTagTree, noteTagMap } from '../utils/tags.js'

export const useTagsStore = defineStore('tags', {
	state: () => ({
		available: true,
		writable: false,
		schemaVersion: null,
		/** ETag of notes.sqlite, null if it doesn't exist yet */
		etag: null,
		tree: [],
		links: [],
		/** Tag filter of the note list */
		selectedTagIds: [],
		untagged: false,
	}),

	getters: {
		tags: (state) => flattenTagTree(state.tree),
		noteTags: (state) => noteTagMap(state.links),
		/** All tags as name paths, e.g. "Work/Project A", sorted for autocompletion */
		tagPaths() {
			return [...this.tags.values()]
				.map((tag) => ({ id: tag.id, path: tag.path, label: tag.path.join('/') }))
				.sort((a, b) => a.label.localeCompare(b.label))
		},
	},

	actions: {
		async load() {
			try {
				const [tags, links] = await Promise.all([api.fetchTags(), api.fetchTagLinks()])
				this.applyTree(tags)
				this.links = links.links
				this.available = true
			} catch (error) {
				// 503: the server has no pdo_sqlite
				if (api.errorStatus(error) === 503) {
					this.available = false
					return
				}
				throw error
			}
		},

		applyTree(data) {
			this.tree = data.tags
			this.etag = data.etag
			this.writable = data.writable
			this.schemaVersion = data.schemaVersion
		},

		/**
		 * Runs an action that changes notes.sqlite; if notes.sqlite was changed by another client in the meantime,
		 * the tags are reloaded and the action is run once more
		 *
		 * @param {(etag: string|null) => Promise<object>} action receives the ETag of notes.sqlite
		 */
		async modify(action) {
			try {
				return await action(this.etag)
			} catch (error) {
				if (api.errorStatus(error) !== 412 && api.errorStatus(error) !== 409) {
					throw error
				}
				await this.load()
				return await action(this.etag)
			}
		},

		/**
		 * @param {number} noteId the note ID
		 * @param {string[][]} tagPaths all tags of the note as name paths
		 */
		async setNoteTags(noteId, tagPaths) {
			await this.modify((etag) => api.setNoteTags(noteId, tagPaths, etag))
			await this.load()
		},

		async batch(operations) {
			const data = await this.modify((etag) => api.tagBatch(operations, etag))
			this.applyTree(data)
			this.links = (await api.fetchTagLinks()).links
		},

		toggleFilter(tagId) {
			this.untagged = false
			const index = this.selectedTagIds.indexOf(tagId)
			if (index === -1) {
				this.selectedTagIds.push(tagId)
			} else {
				this.selectedTagIds.splice(index, 1)
			}
		},

		setUntagged(untagged) {
			this.untagged = untagged
			if (untagged) {
				this.selectedTagIds = []
			}
		},

		clearFilter() {
			this.selectedTagIds = []
			this.untagged = false
		},
	},
})
