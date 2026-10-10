/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import * as api from '../api.js'

const LOCAL_SETTINGS_KEY = 'qownnotes-web-settings'

const DEFAULT_LOCAL_SETTINGS = {
	// Show the notes of the subfolders of the selected folder too
	showSubfolderNotes: true,
	sortOrder: 'modified',
	preview: false,
	sideBySidePreview: false,
	tagFilterMode: 'or',
	includeChildTags: true,
}

/**
 *
 */
function loadLocalSettings() {
	try {
		return { ...DEFAULT_LOCAL_SETTINGS, ...JSON.parse(window.localStorage.getItem(LOCAL_SETTINGS_KEY) ?? '{}') }
	} catch {
		return { ...DEFAULT_LOCAL_SETTINGS }
	}
}

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		// Per-user server settings (see SettingsService)
		server: {
			notesPath: '',
			fileSuffix: '.md',
			ignoreNoteSubFolders: '^\\.',
			subfoldersEnabled: true,
			noteHeaderStyle: 'atx',
			createTagDatabase: true,
			rewriteMediaLinks: true,
		},
		// Settings of the web interface, stored in the browser
		local: loadLocalSettings(),
	}),

	actions: {
		async load() {
			this.server = await api.fetchSettings()
		},

		async save(changes) {
			this.server = await api.saveSettings(changes)
		},

		setLocal(key, value) {
			this.local[key] = value
			window.localStorage.setItem(LOCAL_SETTINGS_KEY, JSON.stringify(this.local))
		},
	},
})
