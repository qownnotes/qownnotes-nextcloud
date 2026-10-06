<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcContent appName="qownnotes">
		<AppNavigation
			:selectedFolder="selectedFolder"
			:favoritesOnly="favoritesOnly"
			@selectFolder="selectFolder"
			@showFavorites="showFavorites"
			@newNote="createNote"
			@openSettings="settingsOpen = true" />

		<NcAppContent
			:pageHeading="t('qownnotes', 'Notes')"
			:showDetails="selectedNoteId !== null"
			@update:showDetails="onShowDetails">
			<template #list>
				<NoteList
					v-model:search="search"
					:notes="filteredNotes"
					:selectedNoteId="selectedNoteId"
					:loading="!notesStore.loaded"
					:heading="listHeading"
					@select="selectNote"
					@newNote="createNote" />
			</template>

			<NoteEditor
				v-if="selectedNote"
				:key="selectedNote.id"
				:noteId="selectedNote.id"
				@deleted="onNoteDeleted" />
			<NcEmptyContent
				v-else-if="loadError"
				:name="t('qownnotes', 'The notes could not be loaded')"
				:description="loadError">
				<template #icon>
					<NoteTextOutline />
				</template>
			</NcEmptyContent>
			<NcEmptyContent
				v-else-if="notesStore.loaded"
				:name="t('qownnotes', 'No note selected')"
				:description="t('qownnotes', 'Select a note or create a new one')">
				<template #icon>
					<NoteTextOutline />
				</template>
				<template #action>
					<NcButton variant="primary" @click="createNote()">
						{{ t('qownnotes', 'New note') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<SettingsDialog v-model:open="settingsOpen" @changed="reloadAll" />
	</NcContent>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import AppNavigation from './components/AppNavigation.vue'
import NoteEditor from './components/NoteEditor.vue'
import NoteList from './components/NoteList.vue'
import SettingsDialog from './components/SettingsDialog.vue'
import { errorMessage } from './api.js'
import logger from './logger.js'
import { folderRoute, noteRoute, routeFolder, routeNoteId } from './router.js'
import { useFoldersStore } from './stores/folders.js'
import { useNotesStore } from './stores/notes.js'
import { useSettingsStore } from './stores/settings.js'
import { useTagsStore } from './stores/tags.js'
import { baseName, isInFolder, matchesSearch, sortNotes } from './utils/notes.js'
import { createNoteHeader, defaultNoteTitle } from './utils/noteTitle.js'
import { matchesTagFilter } from './utils/tags.js'

const REFRESH_INTERVAL = 30 * 1000

export default {
	name: 'App',

	components: {
		AppNavigation,
		NcAppContent,
		NcButton,
		NcContent,
		NcEmptyContent,
		NoteEditor,
		NoteList,
		NoteTextOutline,
		SettingsDialog,
	},

	data() {
		return {
			search: '',
			favoritesOnly: false,
			settingsOpen: false,
			loadError: '',
			refreshTimer: null,
		}
	},

	computed: {
		...mapStores(useNotesStore, useFoldersStore, useTagsStore, useSettingsStore),

		selectedFolder() {
			return routeFolder(this.$route)
		},

		selectedNoteId() {
			return routeNoteId(this.$route)
		},

		selectedNote() {
			return this.selectedNoteId === null ? null : this.notesStore.get(this.selectedNoteId)
		},

		filteredNotes() {
			const { showSubfolderNotes, sortOrder, tagFilterMode, includeChildTags } = this.settingsStore.local
			const tagFilter = {
				tagIds: this.tagsStore.selectedTagIds,
				untagged: this.tagsStore.untagged,
				mode: tagFilterMode,
				includeChildren: includeChildTags,
			}
			const tags = this.tagsStore.tags
			const noteTags = this.tagsStore.noteTags

			const notes = this.notesStore.list.filter((note) => isInFolder(note.category, this.selectedFolder, showSubfolderNotes)
				&& (!this.favoritesOnly || note.favorite)
				&& matchesTagFilter(noteTags.get(note.id), tagFilter, tags)
				&& matchesSearch(note, this.search))

			return sortNotes(notes, sortOrder)
		},

		listHeading() {
			if (this.favoritesOnly) {
				return t('qownnotes', 'Favorites')
			}
			if (this.selectedFolder === null) {
				return t('qownnotes', 'All notes')
			}
			return this.selectedFolder === '' ? t('qownnotes', 'Note folder') : baseName(this.selectedFolder)
		},
	},

	watch: {
		selectedNoteId(id) {
			// Notes that were created by other clients since the last refresh
			if (id !== null && this.notesStore.loaded && !this.selectedNote) {
				this.notesStore.reload(id).catch(() => this.$router.replace(folderRoute(this.selectedFolder)))
			}
		},
	},

	async mounted() {
		await this.reloadAll()
		this.refreshTimer = window.setInterval(this.refresh, REFRESH_INTERVAL)
		document.addEventListener('visibilitychange', this.onVisibilityChange)
	},

	beforeUnmount() {
		window.clearInterval(this.refreshTimer)
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
	},

	methods: {
		t,

		async reloadAll() {
			try {
				await this.settingsStore.load()
				await Promise.all([this.notesStore.load(), this.foldersStore.load(), this.tagsStore.load()])
				this.loadError = ''
			} catch (error) {
				this.loadError = errorMessage(error)
				return
			}

			if (this.selectedNoteId !== null && !this.selectedNote) {
				this.$router.replace(folderRoute(this.selectedFolder))
			}
		},

		async refresh() {
			if (document.hidden) {
				return
			}
			try {
				await Promise.all([this.notesStore.load(), this.foldersStore.load(), this.tagsStore.load()])
			} catch (error) {
				logger.warn('Refreshing the notes failed', { error })
			}
		},

		onVisibilityChange() {
			if (!document.hidden) {
				this.refresh()
			}
		},

		selectNote(id) {
			this.$router.push(noteRoute(id, this.selectedFolder))
		},

		selectFolder(folder) {
			this.favoritesOnly = false
			this.$router.push(folderRoute(folder))
		},

		showFavorites() {
			this.favoritesOnly = true
			this.$router.push(folderRoute(null))
		},

		onShowDetails(show) {
			if (!show) {
				this.$router.push(folderRoute(this.selectedFolder))
			}
		},

		onNoteDeleted() {
			this.$router.push(folderRoute(this.selectedFolder))
		},

		/**
		 * Creates a note in the given or the selected subfolder, with the QOwnNotes headline
		 *
		 * @param {string|null} folder the subfolder
		 */
		async createNote(folder = null) {
			const category = folder ?? this.selectedFolder ?? ''
			// Like QOwnNotes Desktop: the search text or the current date and time becomes the name of the note
			const title = this.search.trim() || defaultNoteTitle(new Date())
			try {
				const note = await this.notesStore.create(category, title, createNoteHeader(title, this.settingsStore.server.noteHeaderStyle))
				this.search = ''
				this.favoritesOnly = false
				this.tagsStore.clearFilter()
				this.foldersStore.load()
				this.$router.push(noteRoute(note.id, folder === null ? this.selectedFolder : folder))
			} catch (error) {
				showError(t('qownnotes', 'The note could not be created: {message}', { message: errorMessage(error) }))
			}
		},
	},
}
</script>
