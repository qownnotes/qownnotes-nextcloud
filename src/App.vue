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
			@openSettings="settingsOpen = true"
			@openTrash="trashOpen = true"
			@openBookmarks="openBookmarks" />

		<NcAppContent
			:pageHeading="t('qownnotes', 'Notes')"
			:pageTitle="pageTitle"
			:showDetails="selectedNoteId !== null"
			@update:showDetails="onShowDetails">
			<template #list>
				<NoteList
					v-model:search="search"
					:notes="filteredNotes"
					:selectedNoteId="selectedNoteId"
					:loading="!notesStore.loaded"
					:heading="listHeading"
					:notesPath="settingsStore.server.notesPath"
					:folderSuggestion="folderSuggestion"
					@select="selectNote"
					@newNote="createNote"
					@notesDeleted="onNotesDeleted"
					@useFolder="folderToUse = $event"
					@openSettings="settingsOpen = true" />
			</template>

			<NoteEditor
				v-if="selectedNote"
				:key="selectedNote.id"
				ref="editor"
				:noteId="selectedNote.id"
				:detailsOpen="detailsOpen"
				@toggleDetails="detailsOpen = !detailsOpen"
				@deleted="onNoteDeleted" />
			<NcEmptyContent
				v-else-if="loadError"
				class="app__empty"
				:name="t('qownnotes', 'The notes could not be loaded')"
				:description="loadError">
				<template #icon>
					<NoteTextOutline />
				</template>
			</NcEmptyContent>
			<NcEmptyContent
				v-else-if="notesStore.loaded"
				class="app__empty"
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

		<NoteSidebar
			v-if="selectedNote && detailsOpen"
			:key="'sidebar-' + selectedNote.id"
			:note="selectedNote"
			@restoreVersion="restoreVersion"
			@close="detailsOpen = false" />

		<SettingsDialog v-model:open="settingsOpen" @changed="reloadAll" />
		<NcDialog
			v-if="folderToUse !== null"
			:name="t('qownnotes', 'Use \u201C{path}\u201D as note folder?', { path: folderToUse })"
			:message="t('qownnotes', 'The note folder changes from \u201C{current}\u201D to \u201C{path}\u201D for the web interface, QOwnNotes Android and other Notes API clients. No files are moved or deleted, and you can switch back in the settings at any time.', { current: settingsStore.server.notesPath, path: folderToUse })"
			:buttons="useFolderButtons"
			@update:open="folderToUse = null" />
		<TrashDialog v-if="trashOpen" @restored="onNoteRestored" @close="trashOpen = false" />
		<BookmarksDialog v-if="bookmarksOpen" @openNote="openBookmarkNote" @close="bookmarksOpen = false" />
	</NcContent>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import AppNavigation from './components/AppNavigation.vue'
import BookmarksDialog from './components/BookmarksDialog.vue'
import NoteEditor from './components/NoteEditor.vue'
import NoteList from './components/NoteList.vue'
import NoteSidebar from './components/NoteSidebar.vue'
import SettingsDialog from './components/SettingsDialog.vue'
import TrashDialog from './components/TrashDialog.vue'
import { errorMessage, fetchNoteFolderCandidates } from './api.js'
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
		BookmarksDialog,
		NcAppContent,
		NcButton,
		NcContent,
		NcDialog,
		NcEmptyContent,
		NoteEditor,
		NoteList,
		NoteSidebar,
		NoteTextOutline,
		SettingsDialog,
		TrashDialog,
	},

	data() {
		return {
			search: '',
			favoritesOnly: false,
			settingsOpen: false,
			trashOpen: false,
			bookmarksOpen: false,
			detailsOpen: false,
			loadError: '',
			refreshTimer: null,
			// Other folders with notes, offered if the note folder is empty
			folderCandidates: [],
			// The note folder that the candidates were loaded for
			folderCandidatesPath: null,
			// The candidate folder whose use the user is asked to confirm
			folderToUse: null,
		}
	},

	computed: {
		...mapStores(useNotesStore, useFoldersStore, useTagsStore, useSettingsStore),

		/**
		 * NcAppContent builds the title from pageHeading and the localized app name, but its lookup of the
		 * localized name returns the ref provided by NcContent (@nextcloud/vue 9.13), so the title would
		 * contain "[object Object]". With pageTitle, the app name is not looked up.
		 */
		pageTitle() {
			const title = this.selectedNote?.title || t('qownnotes', 'Notes')
			return title + ' - ' + t('qownnotes', 'QOwnNotes')
		},

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

		/**
		 * Whether the note folder has no notes at all; only then other folders with notes are suggested
		 */
		noteFolderEmpty() {
			return this.notesStore.loaded
				&& this.loadError === ''
				&& this.notesStore.list.length === 0
				&& this.search === ''
				&& !this.favoritesOnly
				&& this.tagsStore.selectedTagIds.length === 0
				&& !this.tagsStore.untagged
		},

		/** The note folder for which other folders with notes are needed, null if none are needed */
		folderCandidatesWanted() {
			return this.noteFolderEmpty ? this.settingsStore.server.notesPath : null
		},

		folderSuggestion() {
			return this.noteFolderEmpty && this.folderCandidatesPath === this.settingsStore.server.notesPath
				? this.folderCandidates[0] ?? null
				: null
		},

		useFolderButtons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{ label: t('qownnotes', 'Use folder'), variant: 'primary', callback: this.useFolder },
			]
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
		folderCandidatesWanted(notesPath) {
			if (notesPath !== null && notesPath !== this.folderCandidatesPath) {
				this.loadFolderCandidates()
			}
		},

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

		onNotesDeleted(noteIds) {
			if (noteIds.includes(this.selectedNoteId)) {
				this.onNoteDeleted()
			}
		},

		async onNoteRestored(noteId) {
			await Promise.all([this.notesStore.load(), this.foldersStore.load(), this.tagsStore.load()])
			if (noteId !== null && this.notesStore.get(noteId)) {
				this.trashOpen = false
				this.favoritesOnly = false
				this.$router.push(noteRoute(noteId, null))
			}
		},

		async openBookmarks() {
			// The bookmarks are read from the saved notes
			await this.$refs.editor?.save()
			this.bookmarksOpen = true
		},

		openBookmarkNote(noteId) {
			this.bookmarksOpen = false
			this.favoritesOnly = false
			this.$router.push(noteRoute(noteId, null))
		},

		restoreVersion(content) {
			this.$refs.editor?.replaceContent(content)
		},

		async loadFolderCandidates() {
			const notesPath = this.settingsStore.server.notesPath
			try {
				const candidates = await fetchNoteFolderCandidates()
				// The note folder may have been changed meanwhile
				if (notesPath === this.settingsStore.server.notesPath) {
					this.folderCandidates = candidates
					this.folderCandidatesPath = notesPath
				}
			} catch (error) {
				logger.warn('Loading the other folders with notes failed', { error })
			}
		},

		/**
		 * Switches the note folder to the confirmed candidate folder; no files are moved
		 */
		async useFolder() {
			const path = this.folderToUse
			try {
				await this.settingsStore.save({ notesPath: path })
			} catch (error) {
				showError(t('qownnotes', 'The settings could not be saved: {message}', { message: errorMessage(error) }))
				return
			}

			showSuccess(t('qownnotes', 'The note folder is now \u201C{path}\u201D', { path: this.settingsStore.server.notesPath }))
			this.$router.push(folderRoute(null))
			await this.reloadAll()
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

<style scoped>
.app__empty {
	height: 100%;
}
</style>
