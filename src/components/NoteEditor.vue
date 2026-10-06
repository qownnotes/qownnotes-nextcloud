<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="note-editor" :data-note-id="noteId">
		<div class="note-editor__header">
			<div class="note-editor__title-row">
				<div class="note-editor__title">
					<h2 :title="note.title">
						{{ note.title }}
					</h2>
					<span v-if="note.category" class="note-editor__category">
						<FolderOutline :size="16" />
						{{ note.category }}
					</span>
				</div>
				<span class="note-editor__status" :class="'note-editor__status--' + status">
					{{ statusText }}
				</span>
				<NcButton
					variant="tertiary"
					:aria-label="note.favorite ? t('qownnotes', 'Remove from favorites') : t('qownnotes', 'Add to favorites')"
					:title="note.favorite ? t('qownnotes', 'Remove from favorites') : t('qownnotes', 'Add to favorites')"
					@click="toggleFavorite">
					<template #icon>
						<Star v-if="note.favorite" :size="20" class="note-editor__favorite" />
						<StarOutline v-else :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:pressed="preview"
					:aria-label="t('qownnotes', 'Preview')"
					:title="t('qownnotes', 'Preview')"
					class="note-editor__preview-toggle"
					@click="togglePreview">
					<template #icon>
						<EyeOutline :size="20" />
					</template>
				</NcButton>
				<NcActions>
					<NcActionInput
						v-if="!note.readonly"
						v-model="newTitle"
						:label="t('qownnotes', 'Rename note')"
						@submit="rename">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
					</NcActionInput>
					<NcActionButton v-if="!note.readonly" closeAfterClick @click="$refs.fileInput.click()">
						<template #icon>
							<ImagePlus :size="20" />
						</template>
						{{ t('qownnotes', 'Insert image or attachment') }}
					</NcActionButton>
					<NcActionButton v-if="!note.readonly" closeAfterClick @click="deleteDialogOpen = true">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('qownnotes', 'Delete note') }}
					</NcActionButton>
				</NcActions>
			</div>
			<NoteTagEditor v-if="tagsStore.available" :noteId="noteId" :writable="tagsWritable" />
		</div>

		<div class="note-editor__body">
			<NotePreview
				v-if="preview"
				:content="content"
				:noteId="noteId"
				:readonly="note.readonly"
				@toggleTask="onToggleTask" />
			<MarkdownEditor
				v-else
				ref="editor"
				v-model="content"
				:readonly="note.readonly"
				:placeholder="t('qownnotes', 'Write your note …')" />
		</div>

		<input
			ref="fileInput"
			type="file"
			class="hidden-visually"
			multiple
			@change="onFilesSelected">

		<NcDialog
			v-if="conflict"
			:name="t('qownnotes', 'The note was changed in the meantime')"
			size="large"
			noClose
			:buttons="conflictButtons">
			<p>{{ t('qownnotes', 'The note was changed by another app or device while you were editing it. Which version do you want to keep?') }}</p>
			<h3>{{ t('qownnotes', 'Other version') }}</h3>
			<pre class="note-editor__conflict-text">{{ conflict.content }}</pre>
		</NcDialog>

		<NcDialog
			v-if="deleteDialogOpen"
			:name="t('qownnotes', 'Delete note')"
			:message="t('qownnotes', 'Do you want to delete the note \u201C{title}\u201D?', { title: note.title })"
			:buttons="deleteButtons"
			@update:open="deleteDialogOpen = false" />
	</div>
</template>

<script>
import { showError, showWarning } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Delete from 'vue-material-design-icons/Delete.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import ImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import MarkdownEditor from './MarkdownEditor.vue'
import NotePreview from './NotePreview.vue'
import NoteTagEditor from './NoteTagEditor.vue'
import { errorMessage, uploadAttachment } from '../api.js'
import { useFoldersStore } from '../stores/folders.js'
import { NoteConflictError, useNotesStore } from '../stores/notes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'
import { toggleTask } from '../utils/markdown.js'
import { titleFromContent } from '../utils/noteTitle.js'

const AUTOSAVE_DELAY = 1000

export default {
	name: 'NoteEditor',

	components: {
		Delete,
		EyeOutline,
		FolderOutline,
		ImagePlus,
		MarkdownEditor,
		NcActionButton,
		NcActionInput,
		NcActions,
		NcButton,
		NcDialog,
		NotePreview,
		NoteTagEditor,
		PencilOutline,
		Star,
		StarOutline,
	},

	props: {
		noteId: {
			type: Number,
			required: true,
		},
	},

	emits: ['deleted'],

	data() {
		const note = useNotesStore().get(this.noteId)
		const content = note?.content ?? ''
		return {
			content,
			// The text as stored on the server
			savedContent: content,
			// The note is renamed if its first line changes, like in QOwnNotes Desktop; comparing with the
			// title of the first line (instead of the file name) keeps notes whose file name differs from
			// their headline, e.g. "Note 1.md" with "# Note", from being renamed on every save
			baselineTitle: titleFromContent(content),
			saving: false,
			saveError: false,
			saveTimer: null,
			conflict: null,
			newTitle: note?.title ?? '',
			deleteDialogOpen: false,
		}
	},

	computed: {
		...mapStores(useNotesStore, useFoldersStore, useTagsStore, useSettingsStore),

		note() {
			return this.notesStore.get(this.noteId) ?? { id: this.noteId, title: '', category: '', favorite: false, readonly: true }
		},

		preview() {
			return this.settingsStore.local.preview
		},

		dirty() {
			return this.content !== this.savedContent
		},

		tagsWritable() {
			return this.tagsStore.writable || (this.tagsStore.etag === null && this.settingsStore.server.createTagDatabase)
		},

		status() {
			if (this.saving) {
				return 'saving'
			}
			if (this.saveError) {
				return 'error'
			}
			return this.dirty ? 'unsaved' : 'saved'
		},

		statusText() {
			return {
				saving: t('qownnotes', 'Saving …'),
				error: t('qownnotes', 'Not saved'),
				unsaved: t('qownnotes', 'Unsaved changes'),
				saved: '',
			}[this.status]
		},

		conflictButtons() {
			return [
				{ label: t('qownnotes', 'Use other version'), callback: this.takeTheirs },
				{ label: t('qownnotes', 'Keep my version'), variant: 'primary', callback: this.keepMine },
			]
		},

		deleteButtons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{ label: t('qownnotes', 'Delete'), variant: 'error', callback: this.deleteNote },
			]
		},
	},

	watch: {
		content() {
			if (this.dirty && !this.conflict) {
				this.scheduleSave()
			}
		},

		// Changes of other clients, found by the regular refresh of the note list
		'note.content': function(content) {
			if (content !== undefined && !this.dirty && !this.saving && !this.conflict && content !== this.savedContent) {
				this.savedContent = content
				this.content = content
				this.baselineTitle = titleFromContent(content)
			}
		},

		'note.title': function(title) {
			this.newTitle = title
		},
	},

	mounted() {
		window.addEventListener('beforeunload', this.onBeforeUnload)
		if (this.notesStore.get(this.noteId)?.content === undefined) {
			this.notesStore.reload(this.noteId)
		}
	},

	beforeUnmount() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		// Save right away when another note is opened
		if (this.dirty && !this.conflict) {
			this.save()
		}
	},

	methods: {
		t,

		scheduleSave() {
			window.clearTimeout(this.saveTimer)
			this.saveTimer = window.setTimeout(this.save, AUTOSAVE_DELAY)
		},

		async save() {
			window.clearTimeout(this.saveTimer)
			if (this.saving) {
				// Saved again when the running save is done
				return
			}
			if (!this.dirty || this.conflict || this.note.readonly) {
				return
			}

			const content = this.content
			const changes = { content }
			const title = titleFromContent(content)
			if (title !== this.baselineTitle) {
				changes.title = title
			}

			this.saving = true
			try {
				await this.notesStore.update(this.noteId, changes)
				this.savedContent = content
				this.baselineTitle = title
				this.saveError = false
				if (changes.title !== undefined) {
					// The server renamed the file and updated the tag links in notes.sqlite
					this.tagsStore.load()
				}
			} catch (error) {
				if (error instanceof NoteConflictError) {
					this.conflict = error.current
				} else {
					this.saveError = true
					showError(t('qownnotes', 'The note could not be saved: {message}', { message: errorMessage(error) }))
				}
			} finally {
				this.saving = false
			}

			if (this.dirty && !this.conflict && !this.saveError) {
				this.scheduleSave()
			}
		},

		async keepMine() {
			this.conflict = null
			this.saving = true
			try {
				const content = this.content
				await this.notesStore.update(this.noteId, { content }, true)
				this.savedContent = content
				this.saveError = false
			} catch (error) {
				this.saveError = true
				showError(t('qownnotes', 'The note could not be saved: {message}', { message: errorMessage(error) }))
			} finally {
				this.saving = false
			}
		},

		takeTheirs() {
			const content = this.conflict.content ?? ''
			this.conflict = null
			this.savedContent = content
			this.content = content
			this.baselineTitle = titleFromContent(content)
			this.saveError = false
		},

		onBeforeUnload(event) {
			if (this.dirty) {
				this.save()
				event.preventDefault()
			}
		},

		togglePreview() {
			this.settingsStore.setLocal('preview', !this.preview)
		},

		onToggleTask(index) {
			this.content = toggleTask(this.content, index)
		},

		async toggleFavorite() {
			try {
				await this.notesStore.update(this.noteId, { favorite: !this.note.favorite }, true)
			} catch (error) {
				showError(t('qownnotes', 'The note could not be changed: {message}', { message: errorMessage(error) }))
			}
		},

		async rename() {
			const title = this.newTitle.trim()
			if (title === '' || title === this.note.title) {
				return
			}
			await this.save()
			try {
				await this.notesStore.update(this.noteId, { title }, true)
				await this.tagsStore.load()
			} catch (error) {
				showError(t('qownnotes', 'The note could not be renamed: {message}', { message: errorMessage(error) }))
			}
		},

		async deleteNote() {
			window.clearTimeout(this.saveTimer)
			// Nothing has to be saved anymore
			this.savedContent = this.content
			try {
				await this.notesStore.remove(this.noteId)
				this.$emit('deleted')
				this.foldersStore.load()
				this.tagsStore.load()
			} catch (error) {
				showError(t('qownnotes', 'The note could not be deleted: {message}', { message: errorMessage(error) }))
			}
		},

		async onFilesSelected(event) {
			const files = [...event.target.files]
			event.target.value = ''
			const links = []
			for (const file of files) {
				try {
					const link = await uploadAttachment(this.noteId, file)
					const prefix = file.type.startsWith('image/') ? '!' : ''
					links.push(`${prefix}[${file.name.replace(/[[\]]/gu, '')}](${encodeURI(link)})`)
				} catch (error) {
					showWarning(t('qownnotes', 'The file "{name}" could not be uploaded: {message}', { name: file.name, message: errorMessage(error) }))
				}
			}
			if (links.length === 0) {
				return
			}

			if (this.preview) {
				this.content = this.content.replace(/\n*$/u, '\n\n') + links.join('\n') + '\n'
			} else {
				this.$refs.editor.insertText(links.join('\n'))
			}
		},
	},
}
</script>

<style scoped>
.note-editor {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 0 16px 0 calc(var(--default-clickable-area) + 8px);
}

.note-editor__header {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
}

.note-editor__title-row {
	display: flex;
	align-items: center;
	gap: 4px;
}

.note-editor__title {
	flex: 1 1 auto;
	min-width: 0;
}

.note-editor__title h2 {
	margin: 0;
	font-size: 1.4em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.note-editor__category {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
}

.note-editor__status {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.note-editor__status--error {
	color: var(--color-error-text);
}

.note-editor__favorite {
	color: var(--color-favorite, #a08b00);
}

.note-editor__body {
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
}

.note-editor__conflict-text {
	max-height: 40vh;
	padding: 8px;
	overflow: auto;
	white-space: pre-wrap;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
