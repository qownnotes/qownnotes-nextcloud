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
		<template v-if="mode === 'list'">
			<div
				v-if="loading"
				class="bookmarks-dialog__loading"
				role="status"
				:aria-label="t('qownnotes', 'Loading bookmarks')">
				<NcLoadingIcon :size="32" />
			</div>
			<NcEmptyContent
				v-else-if="bookmarks.length === 0"
				:name="t('qownnotes', 'No bookmarks')"
				:description="t('qownnotes', 'Like in QOwnNotes Desktop and Android, bookmarks are list items like \u201C- [Name](https://example.com) #tag description\u201D in notes with the tag \u201Cbookmarks\u201D or with the name \u201CBookmarks\u201D.')">
				<template #icon>
					<BookmarkMultipleOutline />
				</template>
			</NcEmptyContent>
			<template v-else>
				<NcTextField
					ref="search"
					v-model="search"
					class="bookmarks-dialog__search"
					:label="t('qownnotes', 'Search bookmarks')"
					@keydown.enter.prevent />
				<div
					v-if="allTags.length > 0"
					class="bookmarks-dialog__tags"
					:aria-label="t('qownnotes', 'Filter by tag')"
					role="group">
					<NcButton
						v-for="tag in allTags"
						:key="tag"
						size="small"
						:pressed="selectedTags.includes(tag)"
						@update:pressed="toggleTag(tag)">
						#{{ tag }}
					</NcButton>
				</div>
				<p class="bookmarks-dialog__count" role="status">
					{{ n('qownnotes', '{count} bookmark', '{count} bookmarks', visibleBookmarks.length, { count: visibleBookmarks.length }) }}
				</p>
				<ul v-if="visibleBookmarks.length > 0" class="bookmarks-dialog__list" :aria-label="t('qownnotes', 'Bookmarks')">
					<li v-for="bookmark in visibleBookmarks" :key="bookmark.url" class="bookmarks-dialog__item">
						<div class="bookmarks-dialog__details">
							<a
								v-if="isOpenableUrl(bookmark.url)"
								class="bookmarks-dialog__name"
								:href="bookmark.url"
								target="_blank"
								rel="noopener noreferrer">
								{{ bookmark.name || bookmark.url }}
							</a>
							<span v-else class="bookmarks-dialog__name">{{ bookmark.name || bookmark.url }}</span>
							<span class="bookmarks-dialog__url">{{ bookmark.url }}</span>
							<span v-if="bookmark.description" class="bookmarks-dialog__description">{{ bookmark.description }}</span>
							<span v-if="bookmark.tags.length > 0" class="bookmarks-dialog__item-tags">
								<span v-for="tag in bookmark.tags" :key="tag" class="bookmarks-dialog__tag">#{{ tag }}</span>
							</span>
							<span class="bookmarks-dialog__notes">
								<button
									v-for="noteId in bookmark.noteIds"
									:key="noteId"
									type="button"
									class="bookmarks-dialog__note"
									:title="t('qownnotes', 'Open note')"
									@click="$emit('openNote', noteId)">
									{{ noteLabel(noteId) }}
								</button>
							</span>
						</div>
						<div class="bookmarks-dialog__actions">
							<NcButton
								variant="tertiary"
								:disabled="!canModify(bookmark)"
								:aria-label="t('qownnotes', 'Edit {name}', { name: bookmark.name || bookmark.url })"
								:title="t('qownnotes', 'Edit bookmark')"
								@click="startEdit(bookmark)">
								<template #icon>
									<Pencil :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="!canModify(bookmark)"
								:aria-label="t('qownnotes', 'Delete {name}', { name: bookmark.name || bookmark.url })"
								:title="t('qownnotes', 'Delete bookmark')"
								@click="startDelete(bookmark)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</div>
					</li>
				</ul>
				<NcEmptyContent v-else :name="t('qownnotes', 'No bookmarks found')">
					<template #icon>
						<BookmarkMultipleOutline />
					</template>
				</NcEmptyContent>
			</template>
		</template>

		<div v-else-if="mode === 'edit'" class="bookmarks-dialog__form">
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
				:helperText="t('qownnotes', 'Separated by spaces, for example \u201C#news #work\u201D')" />
			<NcTextField
				v-model="form.description"
				:label="t('qownnotes', 'Description')"
				:error="problem === 'description'"
				:helperText="problem === 'description' ? t('qownnotes', 'The description must not contain tags.') : ''" />
			<p v-if="target.noteIds.length > 1" class="bookmarks-dialog__hint">
				{{ t('qownnotes', 'The bookmark is changed in all notes that contain it.') }}
			</p>
		</div>

		<p v-else-if="mode === 'delete'" class="bookmarks-dialog__hint">
			{{ t('qownnotes', 'Do you want to delete the bookmark \u201C{name}\u201D? It is removed from all notes that contain it.', { name: target.name || target.url }) }}
		</p>

		<template v-if="mode !== 'list'" #actions>
			<NcButton :disabled="saving" @click="mode = 'list'">
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
import Delete from 'vue-material-design-icons/Delete.vue'
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
} from '../utils/bookmarks.js'

export default {
	name: 'BookmarksDialog',

	components: {
		BookmarkMultipleOutline,
		Delete,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		Pencil,
	},

	emits: ['close', 'openNote'],

	data() {
		return {
			loading: true,
			search: '',
			selectedTags: [],
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

		visibleBookmarks() {
			return filterBookmarks(this.bookmarks, this.search, this.selectedTags)
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

	methods: {
		t,
		n,
		isOpenableUrl,

		toggleTag(tag) {
			this.selectedTags = this.selectedTags.includes(tag)
				? this.selectedTags.filter((selected) => selected !== tag)
				: [...this.selectedTags, tag]
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
				this.mode = 'list'
				this.target = null
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

.bookmarks-dialog__search {
	margin-bottom: 8px;
}

.bookmarks-dialog__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	max-height: 20vh;
	overflow: auto;
}

.bookmarks-dialog__count {
	margin: 8px 0 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.bookmarks-dialog__list {
	max-height: 55vh;
	margin: 0;
	padding: 0;
	overflow: auto;
	list-style: none;
}

.bookmarks-dialog__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px;
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.bookmarks-dialog__item:hover {
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

.bookmarks-dialog__url,
.bookmarks-dialog__notes {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

.bookmarks-dialog__description {
	overflow-wrap: anywhere;
}

.bookmarks-dialog__item-tags,
.bookmarks-dialog__notes {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 8px;
}

.bookmarks-dialog__tag {
	color: var(--color-primary-element);
	font-size: 0.9em;
}

.bookmarks-dialog__note {
	min-height: auto;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: inherit;
	font-size: inherit;
	font-weight: normal;
	text-decoration: underline;
	cursor: pointer;
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

.bookmarks-dialog__hint {
	color: var(--color-text-maxcontrast);
}
</style>
