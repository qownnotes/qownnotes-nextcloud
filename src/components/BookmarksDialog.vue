<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="dialogName"
		size="large"
		class="bookmarks-dialog"
		isForm
		@submit.prevent="submit"
		@update:open="$emit('close')">
		<div
			v-if="loading"
			class="bookmarks-dialog__loading"
			role="status"
			:aria-label="t('qownnotes', 'Loading bookmarks')">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<NcEmptyContent
				v-if="bookmarks.length === 0 && mode === 'list'"
				:name="t('qownnotes', 'No bookmarks')"
				:description="t('qownnotes', 'Like in QOwnNotes Desktop and Android, bookmarks are list items like \u201C- [Name](https://example.com) #tag description\u201D in notes with the tag \u201Cbookmarks\u201D or with the name \u201CBookmarks\u201D.')">
				<template #icon>
					<BookmarkMultipleOutline />
				</template>
			</NcEmptyContent>
			<!-- Only hidden while editing, so the scroll position and filters are kept -->
			<div v-else v-show="mode === 'list'" class="bookmarks-dialog__browser">
				<NcTextField
					ref="search"
					v-model="search"
					:label="t('qownnotes', 'Search bookmarks')"
					trailingButtonIcon="close"
					:showTrailingButton="search !== ''"
					@trailingButtonClick="search = ''"
					@keydown.enter.prevent>
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcTextField>
				<div
					v-if="allTags.length > 0"
					class="bookmarks-dialog__tags"
					:class="{ 'bookmarks-dialog__tags--expanded': showAllTags }"
					:aria-label="t('qownnotes', 'Filter by tag')"
					role="group">
					<NcButton
						v-for="tag in shownTags"
						:key="tag"
						size="small"
						:pressed="selectedTags.includes(tag)"
						@update:pressed="toggleTag(tag)">
						#{{ tag }}
						<span class="bookmarks-dialog__tag-count">{{ tagCounts.get(tag) ?? 0 }}</span>
					</NcButton>
					<NcButton
						v-if="allTags.length > TAG_LIMIT"
						size="small"
						variant="tertiary"
						:aria-expanded="showAllTags ? 'true' : 'false'"
						@click="showAllTags = !showAllTags">
						<template #icon>
							<ChevronUp v-if="showAllTags" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
						{{ showAllTags ? t('qownnotes', 'Show fewer tags') : t('qownnotes', 'Show all tags') }}
					</NcButton>
				</div>
				<div class="bookmarks-dialog__status">
					<p class="bookmarks-dialog__count" role="status">
						{{ countText }}
					</p>
					<NcButton
						v-if="filtered && visibleBookmarks.length > 0"
						size="small"
						variant="tertiary"
						@click="clearFilters">
						<template #icon>
							<FilterOffOutline :size="20" />
						</template>
						{{ t('qownnotes', 'Clear filters') }}
					</NcButton>
				</div>
				<ul
					v-if="visibleBookmarks.length > 0"
					ref="list"
					class="bookmarks-dialog__list"
					:aria-label="t('qownnotes', 'Bookmarks')">
					<li
						v-for="bookmark in visibleBookmarks"
						:key="bookmark.url"
						class="bookmarks-dialog__item"
						:data-url="bookmark.url">
						<div class="bookmarks-dialog__details">
							<a
								v-if="isOpenableUrl(bookmark.url)"
								class="bookmarks-dialog__name"
								:href="bookmark.url"
								target="_blank"
								rel="noopener noreferrer">
								{{ bookmark.name || bookmark.url }}
								<OpenInNew class="bookmarks-dialog__external" :size="14" />
							</a>
							<span v-else class="bookmarks-dialog__name">{{ bookmark.name || bookmark.url }}</span>
							<span class="bookmarks-dialog__url" :title="bookmark.url">{{ bookmark.url }}</span>
							<span v-if="bookmark.description" class="bookmarks-dialog__description">{{ bookmark.description }}</span>
							<span class="bookmarks-dialog__meta">
								<button
									v-for="tag in bookmark.tags"
									:key="'tag:' + tag"
									type="button"
									class="bookmarks-dialog__chip bookmarks-dialog__chip--tag"
									:class="{ 'bookmarks-dialog__chip--selected': selectedTags.includes(tag) }"
									:aria-pressed="selectedTags.includes(tag) ? 'true' : 'false'"
									:title="t('qownnotes', 'Filter by tag')"
									@click="toggleTag(tag)">
									#{{ tag }}
								</button>
								<button
									v-for="noteId in bookmark.noteIds"
									:key="'note:' + noteId"
									type="button"
									class="bookmarks-dialog__chip"
									:title="t('qownnotes', 'Open note')"
									@click="$emit('openNote', noteId)">
									<FileDocumentOutline :size="16" />
									{{ noteLabel(noteId) }}
								</button>
							</span>
						</div>
						<div class="bookmarks-dialog__actions">
							<NcButton
								class="bookmarks-dialog__edit"
								variant="tertiary"
								:disabled="!canModify(bookmark)"
								:aria-label="t('qownnotes', 'Edit {name}', { name: bookmark.name || bookmark.url })"
								:title="canModify(bookmark) ? t('qownnotes', 'Edit bookmark') : t('qownnotes', 'The bookmark is in a read-only note')"
								@click="startEdit(bookmark)">
								<template #icon>
									<Pencil :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="!canModify(bookmark)"
								:aria-label="t('qownnotes', 'Delete {name}', { name: bookmark.name || bookmark.url })"
								:title="canModify(bookmark) ? t('qownnotes', 'Delete bookmark') : t('qownnotes', 'The bookmark is in a read-only note')"
								@click="startDelete(bookmark)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</div>
					</li>
				</ul>
				<NcEmptyContent v-else class="bookmarks-dialog__no-results" :name="t('qownnotes', 'No bookmarks found')">
					<template #icon>
						<Magnify />
					</template>
					<template v-if="filtered" #action>
						<NcButton @click="clearFilters">
							{{ t('qownnotes', 'Clear filters') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>
		</template>

		<div v-if="mode === 'edit'" class="bookmarks-dialog__form">
			<NcTextField
				ref="nameInput"
				v-model="form.name"
				:label="t('qownnotes', 'Name')"
				:error="problem === 'name'"
				:helperText="problem === 'name' ? t('qownnotes', 'The name must not be empty or contain square brackets.') : ''" />
			<NcTextField
				v-model="form.url"
				:label="t('qownnotes', 'URL')"
				placeholder="https://"
				:error="problem === 'url' || problem === 'duplicate'"
				:helperText="urlHelperText" />
			<NcTextField
				v-model="form.tags"
				:label="t('qownnotes', 'Tags')"
				:error="problem === 'tags'"
				:helperText="problem === 'tags'
					? t('qownnotes', 'Tags can only start with \u201C#\u201D.')
					: t('qownnotes', 'Separated by spaces, for example \u201C#news #work\u201D')" />
			<NcTextField
				v-model="form.description"
				:label="t('qownnotes', 'Description')"
				:error="problem === 'description'"
				:helperText="problem === 'description' ? t('qownnotes', 'The description must not contain tags.') : ''" />
			<div class="bookmarks-dialog__summary">
				<span class="bookmarks-dialog__summary-label">{{ t('qownnotes', 'Saved as') }}</span>
				<code class="bookmarks-dialog__preview">{{ preview }}</code>
				<span class="bookmarks-dialog__summary-label">{{ t('qownnotes', 'Contained in') }}</span>
				<span class="bookmarks-dialog__meta">
					<span v-for="noteId in target.noteIds" :key="noteId" class="bookmarks-dialog__chip bookmarks-dialog__chip--static">
						<FileDocumentOutline :size="16" />
						{{ noteLabel(noteId) }}
					</span>
				</span>
			</div>
			<p v-if="target.noteIds.length > 1" class="bookmarks-dialog__hint">
				{{ t('qownnotes', 'The bookmark is changed in all notes that contain it.') }}
			</p>
		</div>

		<div v-else-if="mode === 'delete'" class="bookmarks-dialog__confirm">
			<p>
				{{ t('qownnotes', 'Do you want to delete the bookmark \u201C{name}\u201D? It is removed from all notes that contain it.', { name: target.name || target.url }) }}
			</p>
			<div class="bookmarks-dialog__card">
				<span class="bookmarks-dialog__name">{{ target.name || target.url }}</span>
				<span class="bookmarks-dialog__url" :title="target.url">{{ target.url }}</span>
				<span v-if="target.description" class="bookmarks-dialog__description">{{ target.description }}</span>
				<span class="bookmarks-dialog__summary">
					<span class="bookmarks-dialog__summary-label">{{ t('qownnotes', 'Contained in') }}</span>
					<span class="bookmarks-dialog__meta">
						<span v-for="noteId in target.noteIds" :key="noteId" class="bookmarks-dialog__chip bookmarks-dialog__chip--static">
							<FileDocumentOutline :size="16" />
							{{ noteLabel(noteId) }}
						</span>
					</span>
				</span>
			</div>
		</div>

		<template v-if="mode !== 'list'" #actions>
			<NcButton ref="cancelButton" :disabled="saving" @click="backToList()">
				{{ t('qownnotes', 'Cancel') }}
			</NcButton>
			<NcButton
				type="submit"
				:variant="mode === 'delete' ? 'error' : 'primary'"
				:disabled="saving || (mode === 'edit' && (problem !== null || !changed))">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ mode === 'delete' ? t('qownnotes', 'Delete') : t('qownnotes', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BookmarkMultipleOutline from 'vue-material-design-icons/BookmarkMultipleOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { errorMessage } from '../api.js'
import { NoteConflictError, useNotesStore } from '../stores/notes.js'
import { useTagsStore } from '../stores/tags.js'
import {
	bookmarkProblem,
	BOOKMARKS_TAG,
	collectBookmarks,
	editBookmark,
	filterBookmarks,
	isBookmarkNote,
	isOpenableUrl,
	parseTagsInput,
	removeBookmark,
	serializeBookmark,
} from '../utils/bookmarks.js'

// Number of tag filters that are shown until all tags are expanded
const TAG_LIMIT = 16

export default {
	name: 'BookmarksDialog',

	components: {
		BookmarkMultipleOutline,
		ChevronDown,
		ChevronUp,
		Delete,
		FileDocumentOutline,
		FilterOffOutline,
		Magnify,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		OpenInNew,
		Pencil,
	},

	emits: ['close', 'openNote'],

	data() {
		return {
			TAG_LIMIT,
			loading: true,
			search: '',
			selectedTags: [],
			showAllTags: false,
			/** "list", "edit" or "delete" */
			mode: 'list',
			/** The bookmark that is edited or deleted */
			target: null,
			form: { name: '', url: '', tags: '', description: '' },
			saving: false,
		}
	},

	computed: {
		...mapStores(useNotesStore, useTagsStore),

		dialogName() {
			if (this.mode === 'edit') {
				return t('qownnotes', 'Edit bookmark')
			}
			if (this.mode === 'delete') {
				return t('qownnotes', 'Delete bookmark')
			}
			return t('qownnotes', 'Bookmarks')
		},

		bookmarkNotes() {
			const tagIds = new Set([...this.tagsStore.tags.values()]
				.filter((tag) => tag.name.toLocaleLowerCase() === BOOKMARKS_TAG)
				.map((tag) => tag.id))
			const noteTags = this.tagsStore.noteTags
			return this.notesStore.list.filter((note) => isBookmarkNote(note, noteTags.get(note.id), tagIds))
		},

		bookmarks() {
			return collectBookmarks(this.bookmarkNotes)
		},

		allTags() {
			const tags = new Set([...this.bookmarks.flatMap((bookmark) => bookmark.tags), ...this.selectedTags])
			return [...tags].sort((a, b) => a.localeCompare(b))
		},

		tagCounts() {
			const counts = new Map()
			for (const tag of this.bookmarks.flatMap((bookmark) => bookmark.tags)) {
				counts.set(tag, (counts.get(tag) ?? 0) + 1)
			}
			return counts
		},

		/** All tags, or the most used and the selected tags while collapsed */
		shownTags() {
			if (this.showAllTags || this.allTags.length <= TAG_LIMIT) {
				return this.allTags
			}
			const mostUsed = [...this.allTags]
				.sort((a, b) => (this.tagCounts.get(b) ?? 0) - (this.tagCounts.get(a) ?? 0))
				.slice(0, TAG_LIMIT)
			return this.allTags.filter((tag) => mostUsed.includes(tag) || this.selectedTags.includes(tag))
		},

		visibleBookmarks() {
			return filterBookmarks(this.bookmarks, this.search, this.selectedTags)
		},

		filtered() {
			return this.search.trim() !== '' || this.selectedTags.length > 0
		},

		countText() {
			const count = this.bookmarks.length
			if (!this.filtered) {
				return n('qownnotes', '{count} bookmark', '{count} bookmarks', count, { count })
			}
			return n('qownnotes', '{visible} of {count} bookmark', '{visible} of {count} bookmarks', count, {
				visible: this.visibleBookmarks.length,
				count,
			})
		},

		preview() {
			return serializeBookmark(this.edited)
		},

		edited() {
			return {
				name: this.form.name.trim(),
				url: this.form.url.trim(),
				tags: parseTagsInput(this.form.tags),
				description: this.form.description.trim(),
			}
		},

		problem() {
			if (this.mode !== 'edit') {
				return null
			}
			const problem = bookmarkProblem(this.edited)
			if (problem === null && this.edited.url !== this.target.url
				&& this.bookmarks.some((bookmark) => bookmark.url === this.edited.url)) {
				return 'duplicate'
			}
			return problem
		},

		changed() {
			const { name, url, tags, description } = this.target
			return this.edited.name !== name || this.edited.url !== url || this.edited.description !== description
				|| this.edited.tags.join(' ') !== tags.join(' ')
		},

		urlHelperText() {
			if (this.problem === 'url') {
				return t('qownnotes', 'The URL needs a scheme like https:// and must not contain spaces.')
			}
			if (this.problem === 'duplicate') {
				return t('qownnotes', 'There already is a bookmark with this URL.')
			}
			return ''
		},
	},

	async mounted() {
		document.addEventListener('keydown', this.handleEscape, true)
		try {
			// The note list may not contain the text of every note yet
			await Promise.all(this.bookmarkNotes
				.filter((note) => note.content === undefined)
				.map((note) => this.notesStore.reload(note.id)))
		} catch (error) {
			showError(t('qownnotes', 'The bookmarks could not be loaded: {message}', { message: errorMessage(error) }))
		} finally {
			this.loading = false
		}
		this.$nextTick(() => this.$refs.search?.focus())
	},

	beforeUnmount() {
		document.removeEventListener('keydown', this.handleEscape, true)
	},

	methods: {
		t,
		n,
		isOpenableUrl,

		/**
		 * Escape returns from editing or deleting to the list instead of closing the dialog
		 *
		 * @param {KeyboardEvent} event the event
		 */
		handleEscape(event) {
			if (event.key !== 'Escape' || this.mode === 'list') {
				return
			}
			event.preventDefault()
			event.stopImmediatePropagation()
			if (!this.saving) {
				this.backToList()
			}
		},

		toggleTag(tag) {
			this.selectedTags = this.selectedTags.includes(tag)
				? this.selectedTags.filter((selected) => selected !== tag)
				: [...this.selectedTags, tag]
		},

		clearFilters() {
			this.search = ''
			this.selectedTags = []
			this.$refs.search?.focus()
		},

		/**
		 * @param {string|null} focusUrl the bookmark whose edit button gets the focus, or null for the search field
		 */
		backToList(focusUrl = this.target?.url ?? null) {
			this.mode = 'list'
			this.target = null
			this.$nextTick(() => {
				const item = focusUrl === null
					? null
					: [...(this.$refs.list?.children ?? [])].find((element) => element.dataset.url === focusUrl)
				const button = item?.querySelector('.bookmarks-dialog__edit')
				if (button) {
					button.focus()
				} else {
					this.$refs.search?.focus()
				}
			})
		},

		noteLabel(noteId) {
			const note = this.notesStore.get(noteId)
			if (!note) {
				return ''
			}
			return note.category ? `${note.category}/${note.title}` : note.title
		},

		canModify(bookmark) {
			return bookmark.noteIds.every((noteId) => this.notesStore.get(noteId)?.readonly !== true)
		},

		startEdit(bookmark) {
			this.target = bookmark
			this.form = {
				name: bookmark.name,
				url: bookmark.url,
				tags: bookmark.tags.map((tag) => '#' + tag).join(' '),
				description: bookmark.description,
			}
			this.mode = 'edit'
			this.$nextTick(() => this.$refs.nameInput?.focus())
		},

		startDelete(bookmark) {
			this.target = bookmark
			this.mode = 'delete'
			this.$nextTick(() => this.$refs.cancelButton?.$el?.focus())
		},

		async submit() {
			if (this.saving || this.mode === 'list' || (this.mode === 'edit' && (this.problem !== null || !this.changed))) {
				return
			}

			const { url, noteIds } = this.target
			const editing = this.mode === 'edit'
			const replacement = this.edited
			const transform = editing
				? (content) => editBookmark(content, url, replacement)
				: (content) => removeBookmark(content, url)

			this.saving = true
			try {
				for (const noteId of noteIds) {
					await this.changeNote(noteId, transform)
				}
				showSuccess(editing ? t('qownnotes', 'The bookmark was saved') : t('qownnotes', 'The bookmark was deleted'))
				this.backToList(editing ? replacement.url : null)
			} catch (error) {
				showError(editing
					? t('qownnotes', 'The bookmark could not be saved: {message}', { message: errorMessage(error) })
					: t('qownnotes', 'The bookmark could not be deleted: {message}', { message: errorMessage(error) }))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Changes the current text of a note; if another client changed the note in the meantime, the change is
		 * applied to its new text
		 *
		 * @param {number} noteId the note ID
		 * @param {(content: string) => string} transform changes the text
		 */
		async changeNote(noteId, transform) {
			const note = await this.notesStore.reload(noteId)
			const content = transform(note.content ?? '')
			if (content === note.content) {
				return
			}
			try {
				await this.notesStore.update(noteId, { content })
			} catch (error) {
				if (!(error instanceof NoteConflictError)) {
					throw error
				}
				const current = error.current.content ?? ''
				if (transform(current) !== current) {
					await this.notesStore.update(noteId, { content: transform(current) })
				}
			}
		},
	},
}
</script>

<style scoped>
.bookmarks-dialog__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 40vh;
}

/* A fixed height, so the dialog doesn't jump while filtering */
.bookmarks-dialog__browser {
	display: flex;
	flex-direction: column;
	gap: 8px;
	height: min(70vh, 760px);
	min-height: 0;
}

.bookmarks-dialog__tags {
	display: flex;
	flex: 0 0 auto;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
}

.bookmarks-dialog__tags--expanded {
	max-height: 30vh;
	overflow: auto;
}

.bookmarks-dialog__tag-count {
	margin-inline-start: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: normal;
}

.bookmarks-dialog__status {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	min-height: var(--clickable-area-small, 34px);
}

.bookmarks-dialog__count {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.bookmarks-dialog__list {
	flex: 1 1 auto;
	min-height: 0;
	margin: 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
	overflow: auto;
	list-style: none;
}

.bookmarks-dialog__no-results {
	flex: 1 1 auto;
	margin: 0;
}

.bookmarks-dialog__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 8px;
	border-bottom: 1px solid var(--color-border);
}

.bookmarks-dialog__item:hover,
.bookmarks-dialog__item:focus-within {
	background-color: var(--color-background-hover);
}

.bookmarks-dialog__details {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.bookmarks-dialog__name {
	font-weight: bold;
	overflow-wrap: anywhere;
}

a.bookmarks-dialog__name:hover,
a.bookmarks-dialog__name:focus-visible {
	text-decoration: underline;
}

.bookmarks-dialog__external {
	display: inline-flex;
	margin-inline-start: 2px;
	color: var(--color-text-maxcontrast);
	vertical-align: middle;
}

.bookmarks-dialog__url {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.bookmarks-dialog__description {
	overflow-wrap: anywhere;
}

.bookmarks-dialog__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 2px;
}

.bookmarks-dialog__chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	max-width: 100%;
	min-height: 0;
	height: auto;
	margin: 0;
	padding: 1px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	background: none;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: normal;
	line-height: 1.5;
	overflow-wrap: anywhere;
	text-align: start;
	cursor: pointer;
}

.bookmarks-dialog__chip:hover,
.bookmarks-dialog__chip:focus-visible {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.bookmarks-dialog__chip:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.bookmarks-dialog__chip--tag {
	color: var(--color-primary-element);
}

.bookmarks-dialog__chip--selected,
.bookmarks-dialog__chip--selected:hover {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.bookmarks-dialog__chip--static,
.bookmarks-dialog__chip--static:hover {
	border-color: var(--color-border);
	background: none;
	color: var(--color-text-maxcontrast);
	cursor: default;
}

.bookmarks-dialog__actions {
	display: flex;
	flex: 0 0 auto;
}

.bookmarks-dialog__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.bookmarks-dialog__summary {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	align-items: center;
	gap: 8px 12px;
	margin-top: 4px;
}

.bookmarks-dialog__summary-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.bookmarks-dialog__preview {
	padding: 4px 8px;
	border-radius: var(--border-radius-element, var(--border-radius));
	background-color: var(--color-background-dark);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.bookmarks-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.bookmarks-dialog__confirm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bookmarks-dialog__confirm p {
	margin: 0;
}

.bookmarks-dialog__card {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.bookmarks-dialog__card .bookmarks-dialog__summary {
	margin-top: 8px;
}

@media (max-width: 600px) {
	/* The dialog content scrolls instead of the list */
	.bookmarks-dialog__browser {
		height: auto;
	}

	.bookmarks-dialog__list {
		overflow: visible;
	}

	.bookmarks-dialog__summary {
		grid-template-columns: minmax(0, 1fr);
		gap: 4px;
	}
}
</style>
