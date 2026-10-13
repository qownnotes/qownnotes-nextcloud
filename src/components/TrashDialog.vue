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
		<div
			v-if="notes === null && !error"
			class="trash-dialog__loading"
			role="status"
			:aria-label="t('qownnotes', 'Loading deleted notes')">
			<NcLoadingIcon :size="32" />
		</div>
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
					<button class="trash-dialog__select" :aria-pressed="key(note) === selectedKey" @click="selectedKey = key(note)">
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
			<section v-if="selected" class="trash-dialog__preview-panel" :aria-label="t('qownnotes', 'Note preview')">
				<h3 class="trash-dialog__preview-title">
					{{ selected.title }}
				</h3>
				<pre class="trash-dialog__preview" tabindex="0">{{ selected.content }}</pre>
			</section>
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
			if (this.restoring !== null) {
				return
			}
			this.restoring = this.key(note)
			try {
				const id = await restoreTrashedNote(note)
				this.notes = this.notes.filter((other) => other !== note)
				if (this.selectedKey === this.key(note)) {
					this.selectedKey = this.notes.length > 0 ? this.key(this.notes[0]) : null
				}
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
.trash-dialog__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 40vh;
}

.trash-dialog__content {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
	gap: 16px;
	min-height: 40vh;
}

.trash-dialog__list {
	max-height: 60vh;
	min-width: 0;
	margin: 0;
	padding: 0;
	overflow: auto;
	list-style: none;
}

.trash-dialog__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px;
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.trash-dialog__item--selected {
	background-color: var(--color-primary-element-light);
}

.trash-dialog__item:not(.trash-dialog__item--selected):hover {
	background-color: var(--color-background-hover);
}

.trash-dialog__select:focus-visible,
.trash-dialog__preview:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.trash-dialog__select {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	align-items: flex-start;
	min-width: 0;
	margin: 0;
	min-height: var(--default-clickable-area, 44px);
	padding: 8px;
	border: none;
	border-radius: var(--border-radius-element);
	background: none;
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.trash-dialog__select:hover,
.trash-dialog__select:focus,
.trash-dialog__select:active {
	background: none;
}

.trash-dialog__title {
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: bold;
}

.trash-dialog__details {
	max-width: 100%;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

.trash-dialog__preview-panel {
	display: flex;
	flex-direction: column;
	min-width: 0;
	max-height: 60vh;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
	overflow: hidden;
}

.trash-dialog__preview-title {
	margin: 0;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	font-weight: bold;
	font-size: 1em;
	line-height: 1.5;
	overflow-wrap: anywhere;
}

.trash-dialog__preview {
	min-height: 0;
	margin: 0;
	padding: 16px;
	overflow: auto;
	font-family: var(--font-face-monospace, monospace);
	line-height: 1.6;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

@media (max-width: 600px) {
	.trash-dialog__content {
		grid-template-columns: minmax(0, 1fr);
		align-content: start;
	}

	.trash-dialog__list {
		max-height: 30vh;
	}

	.trash-dialog__preview-panel {
		max-height: 35vh;
	}
}
</style>
