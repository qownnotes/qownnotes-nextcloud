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
					<span v-if="note.category" class="note-editor__category" :title="note.category">
						<FolderOutline :size="16" />
						<span>{{ note.category }}</span>
					</span>
				</div>
				<span
					class="note-editor__status"
					:class="'note-editor__status--' + status"
					:title="statusText"
					role="status">
					{{ statusText }}
				</span>
				<div class="note-editor__actions" role="group" :aria-label="t('qownnotes', 'Note actions')">
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
					<NcButton
						variant="tertiary"
						:pressed="detailsOpen"
						:aria-label="t('qownnotes', 'Details and versions')"
						:title="t('qownnotes', 'Details and versions')"
						class="note-editor__details-toggle"
						@click="$emit('toggleDetails')">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
					</NcButton>
					<NcActions>
						<NcActionButton v-if="!note.readonly" closeAfterClick @click="showLinkDialog()">
							<template #icon>
								<LinkVariant :size="20" />
							</template>
							{{ t('qownnotes', 'Insert link') }} (Ctrl+L)
						</NcActionButton>
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
			</div>
			<NoteTagEditor v-if="tagsStore.available" :noteId="noteId" :writable="tagsWritable" />
		</div>

		<div class="note-editor__body" :class="{ 'note-editor__body--split': preview && sideBySidePreview }">
			<MarkdownEditor
				v-if="!preview || sideBySidePreview"
				ref="editor"
				v-model="content"
				:readonly="note.readonly"
				:placeholder="t('qownnotes', 'Write your note …')"
				@scroll="onEditorScroll"
				@insertLink="showLinkDialog"
				@openLink="openLink" />
			<NotePreview
				v-if="preview"
				ref="preview"
				:content="content"
				:noteId="noteId"
				:readonly="note.readonly"
				@toggleTask="onToggleTask"
				@scroll="onPreviewScroll"
				@rendered="onPreviewRendered"
				@openLink="openLink" />
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
			<p class="note-editor__conflict-legend">
				<del>{{ t('qownnotes', 'Only in your version') }}</del>
				<ins>{{ t('qownnotes', 'Only in the other version') }}</ins>
			</p>
			<DiffView :from="content" :to="conflict.content ?? ''" />
		</NcDialog>

		<NcDialog
			v-if="deleteDialogOpen"
			:name="t('qownnotes', 'Delete note')"
			:message="t('qownnotes', 'Do you want to delete the note \u201C{title}\u201D?', { title: note.title })"
			:buttons="deleteButtons"
			@update:open="deleteDialogOpen = false" />
		<LinkDialog
			v-if="linkDialogOpen"
			:notes="notesStore.list"
			:noteId="noteId"
			:category="note.category ?? ''"
			:selection="linkSelection"
			:initialTab="linkTab"
			@insert="insertLink"
			@close="closeLinkDialog" />
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
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import DiffView from './DiffView.vue'
import LinkDialog from './LinkDialog.vue'
import MarkdownEditor from './MarkdownEditor.vue'
import NotePreview from './NotePreview.vue'
import NoteTagEditor from './NoteTagEditor.vue'
import { API_BASE, errorMessage, uploadAttachment } from '../api.js'
import { noteRoute, routeFolder } from '../router.js'
import { useFoldersStore } from '../stores/folders.js'
import { NoteConflictError, useNotesStore } from '../stores/notes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'
import { toggleTask } from '../utils/markdown.js'
import { attachmentUrl } from '../utils/mediaLinks.js'
import { findHeadingLine, findLinkedNote, parseLink } from '../utils/noteLinks.js'
import { titleFromContent } from '../utils/noteTitle.js'

const AUTOSAVE_DELAY = 1000

export default {
	name: 'NoteEditor',

	components: {
		Delete,
		DiffView,
		EyeOutline,
		FolderOutline,
		ImagePlus,
		InformationOutline,
		LinkDialog,
		LinkVariant,
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

		detailsOpen: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['deleted', 'toggleDetails'],

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
			linkDialogOpen: false,
			linkSelection: '',
			linkTab: 'web',
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

		sideBySidePreview() {
			return this.settingsStore.local.sideBySidePreview
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
			if (this.note.readonly) {
				return t('qownnotes', 'Read-only')
			}
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

		// The editor and the preview that are shown instead of each other, or beside each other, keep the scroll position
		preview: {
			handler: 'restoreScrollLine',
			flush: 'post',
		},

		sideBySidePreview: {
			handler: 'restoreScrollLine',
			flush: 'post',
		},
	},

	created() {
		// The line at the top of the editor or the preview, the panes are scrolled together like in QOwnNotes Desktop
		this.scrollLine = 0
	},

	async mounted() {
		window.addEventListener('beforeunload', this.onBeforeUnload)
		if (this.notesStore.get(this.noteId)?.content === undefined) {
			await this.notesStore.reload(this.noteId)
		}
		// The heading of a followed note link
		const heading = this.$route.query.heading
		if (typeof heading === 'string') {
			// The loaded text is taken over by the watcher of note.content
			await this.$nextTick()
			this.jumpToHeading(heading)
			const query = { ...this.$route.query }
			delete query.heading
			this.$router.replace({ ...this.$route, query })
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

		async showLinkDialog(selection, tab = 'web') {
			if (this.note.readonly) {
				return
			}
			if (!this.$refs.editor) {
				this.settingsStore.setLocal('preview', false)
				await this.$nextTick()
			}
			this.linkSelection = selection ?? this.$refs.editor?.selectedText() ?? ''
			this.linkTab = tab
			this.linkDialogOpen = true
		},

		insertLink(text) {
			if (!this.note.readonly) {
				this.$refs.editor?.insertText(text)
			}
		},

		closeLinkDialog() {
			this.linkDialogOpen = false
			this.$nextTick(() => this.$refs.editor?.focus())
		},

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

		/**
		 * Replaces the text, e.g. with a previous version, and saves it like an edit of the user
		 *
		 * @param {string} content the new text
		 */
		replaceContent(content) {
			if (this.note.readonly) {
				return
			}
			this.content = content
			this.save()
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

		/**
		 * Follows a link of the note like QOwnNotes Desktop: links to other notes open them (and jump to the
		 * heading of the fragment), "#heading" links jump to the heading, other files and web pages are opened in
		 * a new browser tab
		 *
		 * @param {string} href the link
		 */
		openLink(href) {
			const link = parseLink(href)
			if (link.type === 'external') {
				window.open(link.path, '_blank', 'noopener,noreferrer')
			} else if (link.type === 'heading') {
				this.jumpToHeading(link.fragment)
			} else if (link.type === 'file') {
				const note = findLinkedNote(this.notesStore.list, this.note.category ?? '', link.path)
				if (note?.id === this.noteId) {
					this.jumpToHeading(link.fragment)
				} else if (note) {
					this.$router.push(noteRoute(note.id, routeFolder(this.$route), link.fragment || null))
				} else {
					window.open(attachmentUrl(API_BASE, this.noteId, link.path, true), '_blank', 'noopener,noreferrer')
				}
			}
		},

		/**
		 * @param {string} fragment the URL-encoded heading text or its anchor
		 */
		jumpToHeading(fragment) {
			const line = findHeadingLine(this.content, fragment)
			if (line === null) {
				return
			}
			this.scrollLine = line
			if (this.$refs.editor) {
				this.$refs.editor.jumpToLine(line)
			} else {
				this.$refs.preview?.scrollToLine(line)
			}
		},

		onEditorScroll(line) {
			this.scrollLine = line
			this.$refs.preview?.scrollToLine(line)
		},

		onPreviewScroll(line) {
			this.scrollLine = line
			this.$refs.editor?.scrollToLine(line)
		},

		// The preview changes its height while the note is edited
		onPreviewRendered() {
			this.$refs.preview?.scrollToLine(this.scrollLine)
		},

		restoreScrollLine() {
			this.$refs.editor?.revealLine(this.scrollLine)
			this.$refs.preview?.scrollToLine(this.scrollLine)
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

			if (!this.$refs.editor) {
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
	min-width: 0;
	padding: 0 16px 0 calc(var(--default-clickable-area) + 8px);
	container-type: inline-size;
}

.note-editor__header {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.note-editor__title-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.note-editor__actions {
	display: flex;
	align-items: center;
	flex: 0 0 auto;
	gap: 4px;
	margin-inline-start: auto;
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
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
}

.note-editor__category > span {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.note-editor__category :deep(.material-design-icon) {
	flex-shrink: 0;
}

.note-editor__status {
	flex: 0 1 auto;
	min-width: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
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

.note-editor__body--split {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	grid-template-rows: minmax(0, 1fr);
	gap: 16px;
	overflow: hidden;
}

.note-editor__body--split > * {
	min-width: 0;
	min-height: 0;
	overflow: auto;
}

.note-editor__body--split > .note-preview {
	border-inline-start: 1px solid var(--color-border);
	padding-inline-start: 16px;
}

@container (max-width: 600px) {
	.note-editor__body--split {
		grid-template-columns: minmax(0, 1fr);
		grid-template-rows: minmax(0, 1fr) minmax(0, 1fr);
	}

	.note-editor__body--split > .note-preview {
		border-inline-start: none;
		border-top: 1px solid var(--color-border);
		padding-inline-start: 4px;
	}
}

.note-editor__conflict-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	margin: 16px 0;
}

.note-editor__conflict-legend ins,
.note-editor__conflict-legend del {
	padding: 2px 8px;
	border-radius: var(--border-radius-element);
}

.note-editor__conflict-legend ins {
	text-decoration: none;
	background-color: var(--color-success-hover, rgba(70, 186, 97, 0.3));
}

.note-editor__conflict-legend del {
	background-color: var(--color-error-hover, rgba(229, 50, 64, 0.3));
}
</style>
