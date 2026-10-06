<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppNavigationItem
		:name="isRoot ? t('qownnotes', 'Note folder') : folder.name"
		:class="{ 'folder-item--drop-target': dropTarget }"
		:active="selectedFolder === folder.path"
		:allowCollapse="folder.children.length > 0"
		:open="open"
		:editable="!isRoot && !folder.readonly"
		:editLabel="t('qownnotes', 'Rename subfolder')"
		:draggable="!isRoot"
		:data-folder-path="folder.path"
		@update:open="open = $event"
		@update:name="rename"
		@click="$emit('select', folder.path)"
		@dragstart="onDragStart"
		@dragover="onDragOver"
		@dragleave="dropTarget = false"
		@drop="onDrop">
		<template #icon>
			<FolderOutline v-if="!isRoot" :size="20" />
			<Folder v-else :size="20" />
		</template>
		<template #counter>
			<NcCounterBubble v-if="noteCount > 0" :count="noteCount" />
		</template>
		<template #actions>
			<NcActionButton closeAfterClick @click="$emit('newNote', folder.path)">
				<template #icon>
					<NotePlus :size="20" />
				</template>
				{{ t('qownnotes', 'New note in this folder') }}
			</NcActionButton>
			<NcActionInput
				v-if="!folder.readonly"
				v-model="newFolderName"
				:label="t('qownnotes', 'New subfolder')"
				@submit="createSubFolder">
				<template #icon>
					<FolderPlus :size="20" />
				</template>
			</NcActionInput>
			<NcActionButton v-if="!isRoot && !folder.readonly" closeAfterClick @click="$emit('delete', folder.path)">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('qownnotes', 'Delete subfolder') }}
			</NcActionButton>
		</template>

		<FolderTreeItem
			v-for="child in folder.children"
			:key="child.path"
			:folder="child"
			:selectedFolder="selectedFolder"
			:recursiveCounts="recursiveCounts"
			@select="$emit('select', $event)"
			@newNote="$emit('newNote', $event)"
			@create="$emit('create', $event)"
			@moveFolder="(...args) => $emit('moveFolder', ...args)"
			@moveNote="(...args) => $emit('moveNote', ...args)"
			@delete="$emit('delete', $event)" />
	</NcAppNavigationItem>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import Delete from 'vue-material-design-icons/Delete.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import NotePlus from 'vue-material-design-icons/NotePlus.vue'
import { DRAG_TYPE_FOLDER, DRAG_TYPE_NOTE } from '../utils/dragAndDrop.js'
import { joinPath, parentPath } from '../utils/notes.js'

export default {
	name: 'FolderTreeItem',

	components: {
		Delete,
		Folder,
		FolderOutline,
		FolderPlus,
		NcActionButton,
		NcActionInput,
		NcAppNavigationItem,
		NcCounterBubble,
		NotePlus,
	},

	props: {
		/** Node of the subfolder tree, see SubFolderService::getTree */
		folder: {
			type: Object,
			required: true,
		},

		selectedFolder: {
			type: String,
			default: null,
		},

		/** Whether the counters include the notes of subfolders */
		recursiveCounts: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select', 'newNote', 'create', 'moveFolder', 'moveNote', 'delete'],

	data() {
		return {
			open: this.folder.path === '',
			newFolderName: '',
			dropTarget: false,
		}
	},

	computed: {
		isRoot() {
			return this.folder.path === ''
		},

		noteCount() {
			return this.recursiveCounts ? this.folder.noteCountRecursive : this.folder.noteCount
		},
	},

	watch: {
		// Show the selected folder in the tree
		selectedFolder: {
			immediate: true,
			handler(folder) {
				if (folder !== null && (folder === this.folder.path || folder.startsWith(this.folder.path + '/'))) {
					this.open = true
				}
			},
		},
	},

	methods: {
		t,

		createSubFolder() {
			const name = this.newFolderName.trim()
			if (name !== '') {
				this.$emit('create', joinPath(this.folder.path, name))
				this.open = true
			}
			this.newFolderName = ''
		},

		rename(name) {
			const trimmed = name.trim()
			if (trimmed !== '' && trimmed !== this.folder.name) {
				this.$emit('moveFolder', this.folder.path, joinPath(parentPath(this.folder.path), trimmed))
			}
		},

		onDragStart(event) {
			event.stopPropagation()
			event.dataTransfer.setData(DRAG_TYPE_FOLDER, this.folder.path)
			event.dataTransfer.effectAllowed = 'move'
		},

		onDragOver(event) {
			const types = event.dataTransfer.types
			if (types.includes(DRAG_TYPE_NOTE) || types.includes(DRAG_TYPE_FOLDER)) {
				event.preventDefault()
				event.stopPropagation()
				event.dataTransfer.dropEffect = 'move'
				this.dropTarget = true
			}
		},

		onDrop(event) {
			this.dropTarget = false
			const noteId = event.dataTransfer.getData(DRAG_TYPE_NOTE)
			const folderPath = event.dataTransfer.getData(DRAG_TYPE_FOLDER)
			if (noteId === '' && folderPath === '') {
				return
			}
			event.preventDefault()
			event.stopPropagation()

			if (noteId !== '') {
				this.$emit('moveNote', Number(noteId), this.folder.path)
			} else if (folderPath !== this.folder.path && parentPath(folderPath) !== this.folder.path
				&& !(this.folder.path + '/').startsWith(folderPath + '/')) {
				this.$emit('moveFolder', folderPath, joinPath(this.folder.path, folderPath.slice(folderPath.lastIndexOf('/') + 1)))
			}
		},
	},
}
</script>

<style scoped>
.folder-item--drop-target :deep(.app-navigation-entry) {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
}
</style>
