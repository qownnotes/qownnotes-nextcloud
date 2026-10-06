/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import * as api from '../api.js'
import { folderPaths } from '../utils/notes.js'

export const useFoldersStore = defineStore('folders', {
	state: () => ({
		/** Root node of the subfolder tree, see SubFolderService::getTree */
		tree: null,
	}),

	getters: {
		paths: (state) => folderPaths(state.tree),
	},

	actions: {
		async load() {
			this.tree = await api.fetchSubFolders()
		},

		async create(path) {
			const folder = await api.createSubFolder(path)
			await this.load()
			return folder
		},

		/**
		 * @param {string} path the subfolder
		 * @param {string} newPath the new path of the subfolder
		 * @return {Promise<{folder: object, tagsRelinked: boolean}>}
		 */
		async move(path, newPath) {
			const result = await api.moveSubFolder(path, newPath)
			await this.load()
			return result
		},

		async remove(path) {
			const result = await api.deleteSubFolder(path)
			await this.load()
			return result
		},
	},
})
