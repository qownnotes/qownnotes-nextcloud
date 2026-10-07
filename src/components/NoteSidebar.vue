<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppSidebar
		v-model:active="activeTab"
		class="note-sidebar"
		:name="note.title"
		:subname="note.category"
		@close="$emit('close')">
		<NcAppSidebarTab id="info" :name="t('qownnotes', 'Details')" :order="1">
			<template #icon>
				<InformationOutline :size="20" />
			</template>
			<NcLoadingIcon v-if="!info && !infoError" :size="32" />
			<p v-else-if="infoError" class="note-sidebar__error">
				{{ infoError }}
			</p>
			<dl v-else class="note-sidebar__info">
				<dt>{{ t('qownnotes', 'File') }}</dt>
				<dd class="note-sidebar__path">
					{{ info.path }}
				</dd>
				<dt>{{ t('qownnotes', 'Size') }}</dt>
				<dd>{{ formatFileSize(info.size) }}</dd>
				<dt>{{ t('qownnotes', 'Modified') }}</dt>
				<dd><NcDateTime :timestamp="info.modified * 1000" /></dd>
				<template v-if="tags.length > 0">
					<dt>{{ t('qownnotes', 'Tags') }}</dt>
					<dd>{{ tags.join(', ') }}</dd>
				</template>
			</dl>
			<NcButton
				v-if="info"
				:href="filesUrl"
				target="_blank"
				variant="secondary">
				<template #icon>
					<FolderOpen :size="20" />
				</template>
				{{ t('qownnotes', 'Show in Files') }}
			</NcButton>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="versions" :name="t('qownnotes', 'Versions')" :order="2">
			<template #icon>
				<History :size="20" />
			</template>
			<NcLoadingIcon v-if="versions === null && !versionsError" :size="32" />
			<NcEmptyContent
				v-else-if="versionsError"
				:name="t('qownnotes', 'Versions are not available')"
				:description="versionsError">
				<template #icon>
					<History />
				</template>
			</NcEmptyContent>
			<NcEmptyContent
				v-else-if="versions.length === 0"
				:name="t('qownnotes', 'No previous versions')"
				:description="t('qownnotes', 'Versions are created when the note is changed')">
				<template #icon>
					<History />
				</template>
			</NcEmptyContent>
			<ul v-else class="note-sidebar__versions">
				<li v-for="version in versions" :key="version.timestamp" class="note-sidebar__version">
					<div class="note-sidebar__version-header">
						<NcButton
							variant="tertiary"
							:aria-expanded="expanded === version.timestamp ? 'true' : 'false'"
							class="note-sidebar__version-toggle"
							@click="expanded = expanded === version.timestamp ? null : version.timestamp">
							<NcDateTime :timestamp="version.timestamp * 1000" />
						</NcButton>
						<NcButton
							v-if="!note.readonly"
							variant="secondary"
							:aria-label="t('qownnotes', 'Restore this version')"
							:title="t('qownnotes', 'Restore this version')"
							@click="$emit('restoreVersion', version.data)">
							<template #icon>
								<Restore :size="20" />
							</template>
						</NcButton>
					</div>
					<template v-if="expanded === version.timestamp">
						<p class="note-sidebar__legend">
							<del>{{ t('qownnotes', 'Only in the current text') }}</del>
							<ins>{{ t('qownnotes', 'Only in this version') }}</ins>
						</p>
						<DiffView :html="version.diffHtml" />
					</template>
				</li>
			</ul>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { formatFileSize } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import History from 'vue-material-design-icons/History.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import DiffView from './DiffView.vue'
import { errorMessage, fetchNoteInfo, fetchNoteVersions } from '../api.js'
import { useTagsStore } from '../stores/tags.js'

export default {
	name: 'NoteSidebar',

	components: {
		DiffView,
		FolderOpen,
		History,
		InformationOutline,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcDateTime,
		NcEmptyContent,
		NcLoadingIcon,
		Restore,
	},

	props: {
		note: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'restoreVersion'],

	data() {
		return {
			activeTab: 'info',
			info: null,
			infoError: '',
			versions: null,
			versionsError: '',
			expanded: null,
		}
	},

	computed: {
		...mapStores(useTagsStore),

		tags() {
			const tagIds = this.tagsStore.noteTags.get(this.note.id) ?? new Set()
			return [...tagIds].map((id) => this.tagsStore.tags.get(id)?.path.join('/')).filter(Boolean).sort()
		},

		filesUrl() {
			return generateUrl('/f/{id}', { id: this.note.id })
		},
	},

	watch: {
		// Reload after every save, so the new version and the changed size show up
		'note.etag': {
			immediate: true,
			handler() {
				this.load()
			},
		},

		activeTab() {
			this.load()
		},
	},

	methods: {
		t,
		formatFileSize,

		async load() {
			const noteId = this.note.id
			try {
				const info = await fetchNoteInfo(noteId)
				if (noteId === this.note.id) {
					this.info = info
					this.infoError = ''
				}
			} catch (error) {
				this.infoError = errorMessage(error)
			}

			if (this.activeTab !== 'versions') {
				return
			}
			try {
				const versions = await fetchNoteVersions(noteId)
				if (noteId === this.note.id) {
					this.versions = versions
					this.versionsError = ''
				}
			} catch (error) {
				this.versionsError = errorMessage(error) || t('qownnotes', 'The versions app may be disabled')
			}
		},
	},
}
</script>

<style scoped>
.note-sidebar__info {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin-bottom: 16px;
}

.note-sidebar__info dt {
	color: var(--color-text-maxcontrast);
}

.note-sidebar__path {
	overflow-wrap: anywhere;
}

.note-sidebar__error {
	color: var(--color-error-text);
}

.note-sidebar__version {
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.note-sidebar__version-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.note-sidebar__legend {
	display: flex;
	gap: 16px;
	margin: 4px 0;
}

.note-sidebar__legend ins {
	text-decoration: none;
	background-color: var(--color-success-hover, rgba(70, 186, 97, 0.3));
}

.note-sidebar__legend del {
	background-color: var(--color-error-hover, rgba(229, 50, 64, 0.3));
}
</style>
