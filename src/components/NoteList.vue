<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppContentList class="note-list">
		<div class="note-list__header">
			<NcTextField
				:modelValue="search"
				:label="t('qownnotes', 'Search notes')"
				trailingButtonIcon="close"
				:showTrailingButton="search !== ''"
				class="note-list__search"
				@update:modelValue="$emit('update:search', $event)"
				@trailingButtonClick="$emit('update:search', '')">
				<template #icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>
			<div class="note-list__title">
				<h2 class="note-list__heading">
					{{ heading }}
				</h2>
				<NcActions>
					<NcActionButton closeAfterClick @click="$emit('newNote')">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('qownnotes', 'New note') }}
					</NcActionButton>
					<NcActionCheckbox
						:modelValue="settingsStore.local.sortOrder === 'title'"
						@update:modelValue="settingsStore.setLocal('sortOrder', $event ? 'title' : 'modified')">
						{{ t('qownnotes', 'Sort by name') }}
					</NcActionCheckbox>
					<NcActionCheckbox
						:modelValue="settingsStore.local.showSubfolderNotes"
						@update:modelValue="settingsStore.setLocal('showSubfolderNotes', $event)">
						{{ t('qownnotes', 'Show notes of subfolders') }}
					</NcActionCheckbox>
				</NcActions>
			</div>
			<BulkActions v-if="notesStore.selection.length > 0" @deleted="$emit('notesDeleted', $event)" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="note-list__loading" />
		<NcEmptyContent
			v-else-if="notes.length === 0"
			:name="t('qownnotes', 'No notes found')">
			<template #icon>
				<NoteTextOutline />
			</template>
		</NcEmptyContent>
		<ul v-else :aria-label="t('qownnotes', 'Notes')">
			<NcListItem
				v-for="note in visibleNotes"
				:key="note.id"
				:name="note.title"
				:active="note.id === selectedNoteId"
				:data-note-id="note.id"
				class="note-list__item"
				:class="{ 'note-list__item--selected': notesStore.selection.includes(note.id) }"
				href="#"
				@click.prevent="onClick($event, note)"
				@dragstart="onDragStart($event, note)">
				<template #icon>
					<Star v-if="note.favorite" :size="20" class="note-list__favorite" />
					<NoteTextOutline v-else :size="20" />
				</template>
				<template #subname>
					<span v-if="note.category" class="note-list__category">{{ note.category }}</span>
					<span
						v-for="tag in noteTags(note.id)"
						:key="tag.id"
						class="note-list__tag"
						:style="tagStyle(tag)">{{ tag.name }}</span>
				</template>
				<template #details>
					<NcDateTime :timestamp="note.modified * 1000" relativeTime="narrow" />
				</template>
			</NcListItem>
			<li v-if="visibleNotes.length < notes.length" class="note-list__more">
				<NcButton variant="tertiary" wide @click="limit += PAGE_SIZE">
					{{ t('qownnotes', 'Show more notes') }}
				</NcButton>
			</li>
		</ul>
	</NcAppContentList>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { isDarkTheme } from '@nextcloud/vue/functions/isDarkTheme'
import { mapStores } from 'pinia'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAppContentList from '@nextcloud/vue/components/NcAppContentList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import BulkActions from './BulkActions.vue'
import { useNotesStore } from '../stores/notes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'
import { DRAG_TYPE_NOTE, encodeNoteIds } from '../utils/dragAndDrop.js'
import { tagColor } from '../utils/tags.js'

// Rendering thousands of list items at once is slow, so the list grows on request
const PAGE_SIZE = 200

export default {
	name: 'NoteList',

	components: {
		BulkActions,
		Magnify,
		NcActionButton,
		NcActionCheckbox,
		NcActions,
		NcAppContentList,
		NcButton,
		NcDateTime,
		NcEmptyContent,
		NcListItem,
		NcLoadingIcon,
		NcTextField,
		NoteTextOutline,
		Plus,
		Star,
	},

	props: {
		notes: {
			type: Array,
			required: true,
		},

		selectedNoteId: {
			type: Number,
			default: null,
		},

		search: {
			type: String,
			default: '',
		},

		heading: {
			type: String,
			default: '',
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select', 'newNote', 'update:search', 'notesDeleted'],

	data() {
		return {
			PAGE_SIZE,
			limit: PAGE_SIZE,
		}
	},

	computed: {
		...mapStores(useNotesStore, useSettingsStore, useTagsStore),

		visibleNotes() {
			return this.notes.slice(0, this.limit)
		},
	},

	methods: {
		t,

		noteTags(noteId) {
			const tagIds = this.tagsStore.noteTags.get(noteId)
			if (!tagIds) {
				return []
			}
			return [...tagIds]
				.map((id) => this.tagsStore.tags.get(id))
				.filter(Boolean)
				.sort((a, b) => a.name.localeCompare(b.name))
		},

		tagStyle(tag) {
			const color = tagColor(tag, isDarkTheme)
			return color ? { borderColor: color } : {}
		},

		onClick(event, note) {
			if (event?.ctrlKey || event?.metaKey) {
				this.notesStore.toggleSelection(note.id, this.selectedNoteId)
			} else if (event?.shiftKey) {
				this.notesStore.selectRange(this.notes.map((other) => other.id), note.id, this.selectedNoteId)
			} else {
				this.notesStore.clearSelection()
				this.$emit('select', note.id)
			}
		},

		onDragStart(event, note) {
			// Dragging a selected note drags all selected notes
			const selection = this.notesStore.selection
			event.dataTransfer.setData(DRAG_TYPE_NOTE, encodeNoteIds(selection.includes(note.id) ? selection : [note.id]))
			event.dataTransfer.setData('text/plain', note.title)
			event.dataTransfer.effectAllowed = 'all'
		},
	},
}
</script>

<style scoped>
.note-list__header {
	position: sticky;
	top: 0;
	z-index: 1;
	padding: 8px 8px 0 calc(var(--default-clickable-area) + 8px);
	background-color: var(--color-main-background);
}

.note-list__title {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.note-list__heading {
	margin: 8px 0;
	font-size: 1.2em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.note-list__loading {
	margin-top: 32px;
}

.note-list__item--selected :deep(.list-item) {
	background-color: var(--color-primary-element-light);
}

.note-list__favorite {
	color: var(--color-favorite, #a08b00);
}

.note-list__category {
	margin-inline-end: 6px;
	color: var(--color-text-maxcontrast);
}

.note-list__tag {
	display: inline-block;
	margin-inline-end: 4px;
	padding: 0 6px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	line-height: 1.5;
}

.note-list__more {
	padding: 8px;
}
</style>
