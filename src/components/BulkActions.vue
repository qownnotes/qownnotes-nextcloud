<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="bulk-actions" role="toolbar" :aria-label="t('qownnotes', 'Selected notes')">
		<span class="bulk-actions__count">
			{{ n('qownnotes', '{count} note selected', '{count} notes selected', noteIds.length, { count: noteIds.length }) }}
		</span>
		<NcActions :menuName="t('qownnotes', 'Actions')">
			<NcActionInput
				v-if="tagsStore.available && tagsWritable"
				v-model="newTag"
				:label="t('qownnotes', 'Add tag')"
				:disabled="busy"
				@submit="addTag">
				<template #icon>
					<TagPlus :size="20" />
				</template>
			</NcActionInput>
			<NcActionButton
				v-for="tag in linkedTags"
				:key="tag.id"
				:disabled="busy || !tagsWritable"
				closeAfterClick
				@click="removeTag(tag)">
				<template #icon>
					<TagMinus :size="20" />
				</template>
				{{ t('qownnotes', 'Remove tag \u201C{tag}\u201D', { tag: tag.path.join('/') }) }}
			</NcActionButton>
			<NcActionButton :disabled="busy" closeAfterClick @click="setFavorite(!allFavorites)">
				<template #icon>
					<StarOutline v-if="allFavorites" :size="20" />
					<Star v-else :size="20" />
				</template>
				{{ allFavorites ? t('qownnotes', 'Remove from favorites') : t('qownnotes', 'Add to favorites') }}
			</NcActionButton>
			<NcActionButton :disabled="busy" closeAfterClick @click="deleteDialogOpen = true">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('qownnotes', 'Delete notes') }}
			</NcActionButton>
		</NcActions>
		<NcButton
			variant="tertiary"
			:aria-label="t('qownnotes', 'Clear selection')"
			:title="t('qownnotes', 'Clear selection')"
			@click="notesStore.clearSelection()">
			<template #icon>
				<Close :size="20" />
			</template>
		</NcButton>

		<NcDialog
			v-if="deleteDialogOpen"
			:name="t('qownnotes', 'Delete notes')"
			:message="n('qownnotes', 'Do you want to delete {count} note?', 'Do you want to delete {count} notes?', noteIds.length, { count: noteIds.length })"
			:buttons="deleteButtons"
			@update:open="deleteDialogOpen = false" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Close from 'vue-material-design-icons/Close.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import TagMinus from 'vue-material-design-icons/TagMinus.vue'
import TagPlus from 'vue-material-design-icons/TagPlus.vue'
import { errorMessage } from '../api.js'
import { useFoldersStore } from '../stores/folders.js'
import { useNotesStore } from '../stores/notes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'

export default {
	name: 'BulkActions',

	components: {
		Close,
		Delete,
		NcActionButton,
		NcActionInput,
		NcActions,
		NcButton,
		NcDialog,
		Star,
		StarOutline,
		TagMinus,
		TagPlus,
	},

	emits: ['deleted'],

	data() {
		return {
			newTag: '',
			busy: false,
			deleteDialogOpen: false,
		}
	},

	computed: {
		...mapStores(useNotesStore, useTagsStore, useFoldersStore, useSettingsStore),

		noteIds() {
			return this.notesStore.selection
		},

		tagsWritable() {
			return this.tagsStore.writable || (this.tagsStore.etag === null && this.settingsStore.server.createTagDatabase)
		},

		/** The tags of the selected notes, which can be removed from all of them */
		linkedTags() {
			const tagIds = new Set()
			for (const id of this.noteIds) {
				for (const tagId of this.tagsStore.noteTags.get(id) ?? []) {
					tagIds.add(tagId)
				}
			}
			return [...tagIds]
				.map((id) => this.tagsStore.tags.get(id))
				.filter(Boolean)
				.sort((a, b) => a.path.join('/').localeCompare(b.path.join('/')))
		},

		allFavorites() {
			return this.noteIds.every((id) => this.notesStore.get(id)?.favorite)
		},

		deleteButtons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{ label: t('qownnotes', 'Delete'), variant: 'error', callback: this.deleteNotes },
			]
		},
	},

	methods: {
		t,
		n,

		async run(action, errorText) {
			this.busy = true
			try {
				await action()
			} catch (error) {
				showError(errorText.replace('{message}', errorMessage(error)))
			} finally {
				this.busy = false
			}
		},

		addTag() {
			const tagPath = this.newTag.split('/').map((name) => name.trim()).filter((name) => name !== '')
			this.newTag = ''
			if (tagPath.length === 0) {
				return
			}
			// Missing tags of the path are created by the first "link" operation
			return this.run(
				() => this.tagsStore.batch(this.noteIds.map((noteId) => ({ op: 'link', noteId, tagPath }))),
				t('qownnotes', 'The tags could not be changed: {message}'),
			)
		},

		removeTag(tag) {
			return this.run(
				() => this.tagsStore.batch(this.noteIds
					.filter((noteId) => this.tagsStore.noteTags.get(noteId)?.has(tag.id))
					.map((noteId) => ({ op: 'unlink', noteId, tagId: tag.id }))),
				t('qownnotes', 'The tags could not be changed: {message}'),
			)
		},

		setFavorite(favorite) {
			return this.run(async () => {
				for (const id of this.noteIds) {
					if (this.notesStore.get(id)?.favorite !== favorite) {
						await this.notesStore.update(id, { favorite }, true)
					}
				}
			}, t('qownnotes', 'The note could not be changed: {message}'))
		},

		deleteNotes() {
			const ids = [...this.noteIds]
			return this.run(async () => {
				try {
					for (const id of ids) {
						await this.notesStore.remove(id)
					}
				} finally {
					this.notesStore.clearSelection()
					this.$emit('deleted', ids)
					this.foldersStore.load()
					this.tagsStore.load()
				}
			}, t('qownnotes', 'The note could not be deleted: {message}'))
		},
	},
}
</script>

<style scoped>
.bulk-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 4px 0;
	padding: 2px 4px 2px 12px;
	background-color: var(--color-primary-element-light);
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.bulk-actions__count {
	flex: 1 1 auto;
	font-weight: bold;
}
</style>
