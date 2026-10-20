<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="t('qownnotes', 'Insert link')"
		isForm
		@submit.prevent="insert"
		@update:open="$emit('close')">
		<div class="link-dialog__tabs" role="tablist" :aria-label="t('qownnotes', 'Link type')">
			<button
				v-for="item in tabs"
				:id="`link-tab-${item.id}`"
				:key="item.id"
				type="button"
				role="tab"
				:aria-selected="tab === item.id"
				:aria-controls="`link-panel-${item.id}`"
				:tabindex="tab === item.id ? 0 : -1"
				@click="tab = item.id"
				@keydown.left.prevent="switchTab"
				@keydown.right.prevent="switchTab">
				{{ item.label }}
			</button>
		</div>
		<div
			v-show="tab === 'web'"
			id="link-panel-web"
			role="tabpanel"
			aria-labelledby="link-tab-web"
			class="link-dialog__fields">
			<NcTextField
				ref="urlInput"
				v-model="url"
				:label="t('qownnotes', 'URL')"
				placeholder="https://" />
			<NcButton :disabled="!webUrl || fetching" :loading="fetching" @click="fetchTitle">
				{{ t('qownnotes', 'Fetch title') }}
			</NcButton>
			<p v-if="fetchError" role="status" class="link-dialog__error">
				{{ fetchError }}
			</p>
		</div>
		<div
			v-show="tab === 'note'"
			id="link-panel-note"
			role="tabpanel"
			aria-labelledby="link-tab-note"
			class="link-dialog__fields">
			<NcTextField ref="noteSearch" v-model="query" :label="t('qownnotes', 'Search notes')" />
			<div class="link-dialog__notes">
				<NcButton
					v-for="note in matchingNotes"
					:key="note.id"
					:pressed="selectedNote?.id === note.id"
					variant="tertiary"
					@click="selectNote(note)">
					{{ note.title }}{{ note.category ? ` (${note.category})` : '' }}
				</NcButton>
				<p v-if="matchingNotes.length === 0">
					{{ t('qownnotes', 'No notes found') }}
				</p>
			</div>
		</div>
		<NcTextField v-model="title" :label="t('qownnotes', 'Link text')" />
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('qownnotes', 'Cancel') }}
			</NcButton>
			<NcButton type="submit" variant="primary" :disabled="!canInsert">
				{{ t('qownnotes', 'Insert link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { fetchLinkTitle } from '../api.js'
import { markdownLink, relativeNoteLink } from '../utils/insertLink.js'

export default {
	name: 'LinkDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		notes: { type: Array, required: true },
		noteId: { type: Number, required: true },
		category: { type: String, default: '' },
		selection: { type: String, default: '' },
		initialTab: { type: String, default: 'web', validator: (value) => ['web', 'note'].includes(value) },
	},

	emits: ['close', 'insert'],
	data() {
		const isUrl = /^https?:\/\/\S+$/iu.test(this.selection.trim())
		return {
			tab: this.initialTab,
			url: isUrl ? this.selection.trim() : '',
			webTitle: isUrl ? '' : this.selection,
			fetchedTitle: '',
			noteTitle: isUrl ? '' : this.selection,
			query: '',
			selectedNote: null,
			fetching: false,
			fetchError: '',
		}
	},

	computed: {
		title: {
			get() {
				return this.tab === 'web' ? this.webTitle : this.noteTitle
			},

			set(value) {
				if (this.tab === 'web') {
					this.webTitle = value
				} else {
					this.noteTitle = value
				}
			},
		},

		tabs() {
			return [{ id: 'web', label: t('qownnotes', 'Web link') }, { id: 'note', label: t('qownnotes', 'Link to note') }]
		},

		webUrl() {
			try {
				const url = new URL(this.url.trim())
				return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''
			} catch {
				return ''
			}
		},

		matchingNotes() {
			const query = this.query.trim().toLocaleLowerCase()
			return this.notes.filter((note) => note.id !== this.noteId && `${note.title} ${note.category ?? ''}`.toLocaleLowerCase().includes(query))
				.toSorted((a, b) => a.title.localeCompare(b.title) || (a.category ?? '').localeCompare(b.category ?? ''))
		},

		canInsert() {
			return this.tab === 'web' ? !!this.webUrl : !!this.selectedNote
		},
	},

	watch: {
		tab() {
			if (this.tab === 'web') {
				this.scheduleFetch()
			} else {
				this.cancelFetch()
			}
		},

		url() {
			this.cancelFetch()
			if (this.webTitle === this.fetchedTitle) {
				this.webTitle = ''
			}
			this.fetchedTitle = ''
			this.fetchError = ''
			this.scheduleFetch()
		},
	},

	created() {
		this.fetchController = null
		this.fetchTimer = null
		this.scheduleFetch()
	},

	mounted() {
		this.$nextTick(() => (this.tab === 'note' ? this.$refs.noteSearch : this.$refs.urlInput)?.focus?.())
	},

	beforeUnmount() {
		this.cancelFetch()
	},

	methods: {
		t,
		switchTab() {
			this.tab = this.tab === 'web' ? 'note' : 'web'
			this.$nextTick(() => document.getElementById(`link-tab-${this.tab}`)?.focus())
		},

		selectNote(note) {
			if (!this.noteTitle || this.noteTitle === this.selectedNote?.title) {
				this.noteTitle = note.title
			}
			this.selectedNote = note
		},

		scheduleFetch() {
			if (this.tab === 'web' && this.webUrl && !this.webTitle.trim()) {
				this.fetchTimer = window.setTimeout(() => {
					if (!this.webTitle.trim()) {
						this.fetchTitle()
					}
				}, 600)
			}
		},

		cancelFetch() {
			window.clearTimeout(this.fetchTimer)
			this.fetchController?.abort()
			this.fetchController = null
			this.fetching = false
		},

		async fetchTitle() {
			this.cancelFetch()
			if (!this.webUrl) {
				return
			}
			const controller = new AbortController()
			this.fetchController = controller
			const previousTitle = this.webTitle
			this.fetching = true
			this.fetchError = ''
			try {
				const title = await fetchLinkTitle(this.webUrl, controller.signal)
				if (controller.signal.aborted) {
					return
				}
				if (title && this.webTitle === previousTitle) {
					this.webTitle = title
					this.fetchedTitle = title
				}
				if (!title) {
					this.fetchError = t('qownnotes', 'No page title found. You can enter link text yourself.')
				}
			} catch {
				if (!controller.signal.aborted) {
					this.fetchError = t('qownnotes', 'The title could not be fetched. You can enter link text yourself.')
				}
			} finally {
				if (this.fetchController === controller) {
					this.fetching = false
					this.fetchController = null
				}
			}
		},

		insert() {
			if (!this.canInsert) {
				return
			}
			const href = this.tab === 'web' ? this.webUrl : relativeNoteLink(this.category, this.selectedNote)
			this.$emit('insert', markdownLink(this.title, href))
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.link-dialog__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.link-dialog__tabs [aria-selected='true'] {
	border-color: var(--color-primary-element);
	font-weight: bold;
}

.link-dialog__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 16px;
}

.link-dialog__notes {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 260px;
	overflow: auto;
}

.link-dialog__notes :deep(.button-vue__text) {
	white-space: normal;
	overflow-wrap: anywhere;
}

.link-dialog__error {
	color: var(--color-text-maxcontrast);
}
</style>
