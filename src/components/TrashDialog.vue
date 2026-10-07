<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="t('qownnotes', 'Deleted notes')"
		size="large"
		class="trash-dialog"
		@update:open="$emit('close')">
		<NcLoadingIcon v-if="notes === null && !error" :size="32" />
		<NcEmptyContent
			v-else-if="error"
			:name="t('qownnotes', 'Deleted notes are not available')"
			:description="error">
			<template #icon>
				<Delete />
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="notes.length === 0" :name="t('qownnotes', 'No deleted notes')">
			<template #icon>
				<Delete />
			</template>
		</NcEmptyContent>
		<div v-else class="trash-dialog__content">
			<ul class="trash-dialog__list" :aria-label="t('qownnotes', 'Deleted notes')">
				<li
					v-for="note in notes"
					:key="key(note)"
					class="trash-dialog__item"
					:class="{ 'trash-dialog__item--selected': key(note) === selectedKey }">
					<button class="trash-dialog__select" @click="selectedKey = key(note)">
						<span class="trash-dialog__title">{{ note.title }}</span>
						<span class="trash-dialog__details">
							<span v-if="note.subFolderPath">{{ note.subFolderPath }} · </span>
							<NcDateTime :timestamp="note.deleted * 1000" />
						</span>
					</button>
					<NcButton
						variant="secondary"
						:disabled="restoring !== null"
						:aria-label="t('qownnotes', 'Restore {title}', { title: note.title })"
						:title="t('qownnotes', 'Restore')"
						@click="restore(note)">
						<template #icon>
							<NcLoadingIcon v-if="restoring === key(note)" :size="20" />
							<DeleteRestore v-else :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<pre v-if="selected" class="trash-dialog__preview">{{ selected.content }}</pre>
		</div>
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Delete from 'vue-material-design-icons/Delete.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import { errorMessage, fetchTrash, restoreTrashedNote } from '../api.js'

export default {
	name: 'TrashDialog',

	components: {
		Delete,
		DeleteRestore,
		NcButton,
		NcDateTime,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
	},

	emits: ['close', 'restored'],

	data() {
		return {
			notes: null,
			error: '',
			selectedKey: null,
			restoring: null,
		}
	},

	computed: {
		selected() {
			return this.notes?.find((note) => this.key(note) === this.selectedKey) ?? null
		},
	},

	async mounted() {
		try {
			this.notes = await fetchTrash()
			this.selectedKey = this.notes.length > 0 ? this.key(this.notes[0]) : null
		} catch (error) {
			this.error = errorMessage(error) || t('qownnotes', 'The deleted files app may be disabled')
		}
	},

	methods: {
		t,

		key(note) {
			return `${note.deleted}:${note.originalLocation}`
		},

		async restore(note) {
			this.restoring = this.key(note)
			try {
				const id = await restoreTrashedNote(note)
				this.notes = this.notes.filter((other) => other !== note)
				this.$emit('restored', id)
			} catch (error) {
				showError(t('qownnotes', 'The note could not be restored: {message}', { message: errorMessage(error) }))
			} finally {
				this.restoring = null
			}
		},
	},
}
</script>

<style scoped>
.trash-dialog__content {
	display: grid;
	grid-template-columns: minmax(200px, 1fr) 2fr;
	gap: 12px;
	min-height: 40vh;
}

.trash-dialog__list {
	max-height: 60vh;
	overflow: auto;
}

.trash-dialog__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 2px 4px;
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.trash-dialog__item--selected {
	background-color: var(--color-primary-element-light);
}

.trash-dialog__select {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	align-items: flex-start;
	min-width: 0;
	margin: 0;
	padding: 4px;
	border: none;
	background: none;
	text-align: start;
	cursor: pointer;
}

.trash-dialog__title {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: bold;
}

.trash-dialog__details {
	color: var(--color-text-maxcontrast);
}

.trash-dialog__preview {
	max-height: 60vh;
	margin: 0;
	padding: 8px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
