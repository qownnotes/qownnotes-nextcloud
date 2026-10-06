<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppNavigationItem
		:name="tag.name"
		:class="{ 'tag-item--drop-target': dropTarget }"
		:active="selected"
		:allowCollapse="tag.children.length > 0"
		:open="open"
		:editable="writable"
		:editLabel="t('qownnotes', 'Rename tag')"
		:data-tag-id="tag.id"
		@update:open="open = $event"
		@update:name="rename"
		@click="$emit('toggle', tag.id)"
		@dragover="onDragOver"
		@dragleave="dropTarget = false"
		@drop="onDrop">
		<template #icon>
			<TagIcon :size="20" :fillColor="color ?? undefined" />
		</template>
		<template #counter>
			<NcCounterBubble v-if="tag.noteCount > 0" :count="tag.noteCount" />
		</template>
		<template v-if="writable" #actions>
			<NcActionInput
				v-model="newTagName"
				:label="t('qownnotes', 'New child tag')"
				@submit="createChild">
				<template #icon>
					<Plus :size="20" />
				</template>
			</NcActionInput>
			<NcActionButton closeAfterClick @click="$emit('delete', tag)">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('qownnotes', 'Delete tag') }}
			</NcActionButton>
		</template>

		<TagTreeItem
			v-for="child in tag.children"
			:key="child.id"
			:tag="child"
			:selectedTagIds="selectedTagIds"
			:writable="writable"
			:dark="dark"
			@toggle="$emit('toggle', $event)"
			@operations="$emit('operations', $event)"
			@linkNote="(...args) => $emit('linkNote', ...args)"
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
import Plus from 'vue-material-design-icons/Plus.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import { DRAG_TYPE_NOTE } from '../utils/dragAndDrop.js'
import { tagColor } from '../utils/tags.js'

export default {
	name: 'TagTreeItem',

	components: {
		Delete,
		NcActionButton,
		NcActionInput,
		NcAppNavigationItem,
		NcCounterBubble,
		Plus,
		TagIcon,
	},

	props: {
		/** Node of the tag tree, see TagService::getTagTree */
		tag: {
			type: Object,
			required: true,
		},

		selectedTagIds: {
			type: Array,
			required: true,
		},

		writable: {
			type: Boolean,
			default: false,
		},

		dark: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle', 'operations', 'linkNote', 'delete'],

	data() {
		return {
			open: false,
			newTagName: '',
			dropTarget: false,
		}
	},

	computed: {
		selected() {
			return this.selectedTagIds.includes(this.tag.id)
		},

		color() {
			return tagColor(this.tag, this.dark)
		},
	},

	methods: {
		t,

		rename(name) {
			const trimmed = name.trim()
			if (trimmed !== '' && trimmed !== this.tag.name) {
				this.$emit('operations', [{ op: 'update', id: this.tag.id, name: trimmed }])
			}
		},

		createChild() {
			const name = this.newTagName.trim()
			if (name !== '') {
				this.$emit('operations', [{ op: 'create', name, parentId: this.tag.id }])
				this.open = true
			}
			this.newTagName = ''
		},

		onDragOver(event) {
			if (this.writable && event.dataTransfer.types.includes(DRAG_TYPE_NOTE)) {
				event.preventDefault()
				event.stopPropagation()
				event.dataTransfer.dropEffect = 'link'
				this.dropTarget = true
			}
		},

		onDrop(event) {
			this.dropTarget = false
			const noteId = event.dataTransfer.getData(DRAG_TYPE_NOTE)
			if (noteId !== '') {
				event.preventDefault()
				event.stopPropagation()
				this.$emit('linkNote', Number(noteId), this.tag.id)
			}
		},
	},
}
</script>

<style scoped>
.tag-item--drop-target :deep(.app-navigation-entry) {
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
}
</style>
