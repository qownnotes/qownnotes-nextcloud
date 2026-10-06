<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSelect
		class="note-tag-editor"
		:modelValue="selected"
		:options="options"
		:inputLabel="t('qownnotes', 'Tags')"
		:placeholder="t('qownnotes', 'Add tags, use / for child tags')"
		:disabled="!writable || saving"
		:loading="saving"
		multiple
		taggable
		pushTags
		keepOpen
		@update:modelValue="save" />
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { errorMessage } from '../api.js'
import { useTagsStore } from '../stores/tags.js'

/**
 * Splits a tag path like "Work / Project A" into its names
 *
 * @param {string} label the tag path
 * @return {string[]}
 */
function splitTagPath(label) {
	return label.split('/').map((name) => name.trim()).filter((name) => name !== '')
}

export default {
	name: 'NoteTagEditor',

	components: {
		NcSelect,
	},

	props: {
		noteId: {
			type: Number,
			required: true,
		},

		writable: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			saving: false,
		}
	},

	computed: {
		...mapStores(useTagsStore),

		options() {
			return this.tagsStore.tagPaths.map((tag) => tag.label)
		},

		selected() {
			const tagIds = this.tagsStore.noteTags.get(this.noteId) ?? new Set()
			return [...tagIds]
				.map((id) => this.tagsStore.tags.get(id)?.path.join('/'))
				.filter(Boolean)
				.sort()
		},
	},

	methods: {
		t,

		async save(labels) {
			const paths = labels.map((label) => splitTagPath(String(label))).filter((path) => path.length > 0)
			this.saving = true
			try {
				await this.tagsStore.setNoteTags(this.noteId, paths)
			} catch (error) {
				showError(t('qownnotes', 'The tags could not be saved: {message}', { message: errorMessage(error) }))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.note-tag-editor {
	width: 100%;
}
</style>
