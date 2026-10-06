<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppNavigation :aria-label="t('qownnotes', 'Note folders and tags')">
		<template #list>
			<NcAppNavigationNew :text="t('qownnotes', 'New note')" @click="$emit('newNote')">
				<template #icon>
					<Plus :size="20" />
				</template>
			</NcAppNavigationNew>

			<NcAppNavigationItem
				:name="t('qownnotes', 'All notes')"
				:active="selectedFolder === null && !favoritesOnly"
				@click="$emit('selectFolder', null)">
				<template #icon>
					<NoteTextOutline :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="notesStore.notes.size" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('qownnotes', 'Favorites')"
				:active="favoritesOnly"
				@click="$emit('showFavorites')">
				<template #icon>
					<Star :size="20" />
				</template>
			</NcAppNavigationItem>

			<template v-if="settingsStore.server.subfoldersEnabled && foldersStore.tree">
				<NcAppNavigationCaption :name="t('qownnotes', 'Subfolders')" />
				<FolderTreeItem
					:folder="foldersStore.tree"
					:selectedFolder="selectedFolder"
					:recursiveCounts="settingsStore.local.showSubfolderNotes"
					@select="$emit('selectFolder', $event)"
					@newNote="$emit('newNote', $event)"
					@create="createFolder"
					@moveFolder="moveFolder"
					@moveNote="moveNote"
					@delete="folderToDelete = $event" />
			</template>

			<template v-if="tagsStore.available">
				<NcAppNavigationCaption :name="t('qownnotes', 'Tags')">
					<template #actions>
						<NcActionCheckbox
							:modelValue="settingsStore.local.tagFilterMode === 'and'"
							@update:modelValue="settingsStore.setLocal('tagFilterMode', $event ? 'and' : 'or')">
							{{ t('qownnotes', 'Notes need all selected tags') }}
						</NcActionCheckbox>
						<NcActionCheckbox
							:modelValue="settingsStore.local.includeChildTags"
							@update:modelValue="settingsStore.setLocal('includeChildTags', $event)">
							{{ t('qownnotes', 'Include notes of child tags') }}
						</NcActionCheckbox>
						<NcActionInput
							v-if="tagsWritable"
							v-model="newTagName"
							:label="t('qownnotes', 'New tag')"
							@submit="createTag">
							<template #icon>
								<Plus :size="20" />
							</template>
						</NcActionInput>
					</template>
				</NcAppNavigationCaption>
				<NcAppNavigationItem
					:name="t('qownnotes', 'Untagged notes')"
					:active="tagsStore.untagged"
					@click="tagsStore.setUntagged(!tagsStore.untagged)">
					<template #icon>
						<TagOff :size="20" />
					</template>
				</NcAppNavigationItem>
				<TagTreeItem
					v-for="tag in tagsStore.tree"
					:key="tag.id"
					:tag="tag"
					:selectedTagIds="tagsStore.selectedTagIds"
					:writable="tagsWritable"
					:dark="isDarkTheme"
					@toggle="tagsStore.toggleFilter($event)"
					@operations="runTagOperations"
					@linkNote="linkNote"
					@delete="tagToDelete = $event" />
				<NcAppNavigationItem
					v-if="tagsStore.selectedTagIds.length > 0 || tagsStore.untagged"
					:name="t('qownnotes', 'Clear tag filter')"
					@click="tagsStore.clearFilter()">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</template>

		<template #footer>
			<ul class="app-navigation-entry__settings">
				<NcAppNavigationItem :name="t('qownnotes', 'Settings')" @click="$emit('openSettings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</ul>
		</template>

		<NcDialog
			v-if="folderToDelete !== null"
			:name="t('qownnotes', 'Delete subfolder')"
			:message="t('qownnotes', 'Do you want to delete the subfolder \u201C{path}\u201D with all its notes?', { path: folderToDelete })"
			:buttons="deleteFolderButtons"
			@update:open="folderToDelete = null" />
		<NcDialog
			v-if="tagToDelete !== null"
			:name="t('qownnotes', 'Delete tag')"
			:message="t('qownnotes', 'Do you want to delete the tag \u201C{name}\u201D with all its child tags? The notes are not deleted.', { name: tagToDelete.name })"
			:buttons="deleteTagButtons"
			@update:open="tagToDelete = null" />
	</NcAppNavigation>
</template>

<script>
import { showError, showWarning } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { isDarkTheme } from '@nextcloud/vue/functions/isDarkTheme'
import { mapStores } from 'pinia'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import TagOff from 'vue-material-design-icons/TagOff.vue'
import FolderTreeItem from './FolderTreeItem.vue'
import TagTreeItem from './TagTreeItem.vue'
import { errorMessage } from '../api.js'
import { folderRoute } from '../router.js'
import { useFoldersStore } from '../stores/folders.js'
import { useNotesStore } from '../stores/notes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'

export default {
	name: 'AppNavigation',

	components: {
		Close,
		Cog,
		FolderTreeItem,
		NcActionCheckbox,
		NcActionInput,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcCounterBubble,
		NcDialog,
		NoteTextOutline,
		Plus,
		Star,
		TagOff,
		TagTreeItem,
	},

	props: {
		selectedFolder: {
			type: String,
			default: null,
		},

		favoritesOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['selectFolder', 'showFavorites', 'newNote', 'openSettings'],

	data() {
		return {
			isDarkTheme,
			newTagName: '',
			folderToDelete: null,
			tagToDelete: null,
		}
	},

	computed: {
		...mapStores(useNotesStore, useFoldersStore, useTagsStore, useSettingsStore),

		tagsWritable() {
			// Without notes.sqlite (etag null), the first change creates it if the user allows it
			return this.tagsStore.writable || (this.tagsStore.etag === null && this.settingsStore.server.createTagDatabase)
		},

		deleteFolderButtons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{ label: t('qownnotes', 'Delete'), variant: 'error', callback: () => this.deleteFolder(this.folderToDelete) },
			]
		},

		deleteTagButtons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{ label: t('qownnotes', 'Delete'), variant: 'error', callback: () => this.runTagOperations([{ op: 'delete', id: this.tagToDelete.id }]) },
			]
		},
	},

	methods: {
		t,

		async createFolder(path) {
			try {
				await this.foldersStore.create(path)
			} catch (error) {
				showError(t('qownnotes', 'The subfolder could not be created: {message}', { message: errorMessage(error) }))
			}
		},

		async moveFolder(path, newPath) {
			try {
				const result = await this.foldersStore.move(path, newPath)
				const movedPath = result.folder.path
				if (this.selectedFolder !== null && (this.selectedFolder === path || this.selectedFolder.startsWith(path + '/'))) {
					this.$router.replace(folderRoute(movedPath + this.selectedFolder.slice(path.length)))
				}
				if (!result.tagsRelinked) {
					showWarning(t('qownnotes', 'The tags of the notes in the subfolder could not be updated'))
				}
				await Promise.all([this.notesStore.load(), this.tagsStore.load()])
			} catch (error) {
				showError(t('qownnotes', 'The subfolder could not be moved: {message}', { message: errorMessage(error) }))
			}
		},

		async deleteFolder(path) {
			try {
				await this.foldersStore.remove(path)
				if (this.selectedFolder !== null && (this.selectedFolder === path || this.selectedFolder.startsWith(path + '/'))) {
					this.$router.replace(folderRoute(null))
				}
				await Promise.all([this.notesStore.load(), this.tagsStore.load()])
			} catch (error) {
				showError(t('qownnotes', 'The subfolder could not be deleted: {message}', { message: errorMessage(error) }))
			}
		},

		async moveNote(noteId, category) {
			const note = this.notesStore.get(noteId)
			if (!note || note.category === category) {
				return
			}
			try {
				// Moving doesn't change the text, so changes of other clients can't get lost
				await this.notesStore.update(noteId, { category }, true)
				await Promise.all([this.foldersStore.load(), this.tagsStore.load()])
			} catch (error) {
				showError(t('qownnotes', 'The note could not be moved: {message}', { message: errorMessage(error) }))
			}
		},

		async runTagOperations(operations) {
			try {
				await this.tagsStore.batch(operations)
			} catch (error) {
				showError(t('qownnotes', 'The tags could not be changed: {message}', { message: errorMessage(error) }))
			}
		},

		linkNote(noteId, tagId) {
			return this.runTagOperations([{ op: 'link', noteId, tagId }])
		},

		createTag() {
			const name = this.newTagName.trim()
			this.newTagName = ''
			if (name !== '') {
				return this.runTagOperations([{ op: 'create', name }])
			}
		},
	},
}
</script>
