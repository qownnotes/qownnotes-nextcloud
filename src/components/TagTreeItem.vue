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
		:draggable="writable"
		:data-tag-id="tag.id"
		@update:open="open = $event"
		@update:name="rename"
		@click="$emit('toggle', tag.id)"
		@dragstart="onDragStart"
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
			<NcActionButton closeAfterClick @click="$emit('editColor', tag)">
				<template #icon>
					<Palette :size="20" />
				</template>
				{{ t('qownnotes', 'Change color') }}
			</NcActionButton>
			<NcActionButton v-if="tag.parentId !== 0" closeAfterClick @click="moveTo(0)">
				<template #icon>
					<ArrowUpLeft :size="20" />
				</template>
				{{ t('qownnotes', 'Move to the top level') }}
			</NcActionButton>
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
			@linkNotes="(...args) => $emit('linkNotes', ...args)"
			@editColor="$emit('editColor', $event)"
			@moveTag="(...args) => $emit('moveTag', ...args)"
			@delete="$emit('delete', $event)" />
	</NcAppNavigationItem>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import ArrowUpLeft from 'vue-material-design-icons/ArrowUpLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import { decodeNoteIds, DRAG_TYPE_NOTE, DRAG_TYPE_TAG } from '../utils/dragAndDrop.js'
import { tagColor } from '../utils/tags.js'

export default {
	name: 'TagTreeItem',

	components: {
		ArrowUpLeft,
		Delete,
		NcActionButton,
		NcActionInput,
		NcAppNavigationItem,
		NcCounterBubble,
		Palette,
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

	emits: ['toggle', 'operations', 'linkNotes', 'editColor', 'moveTag', 'delete'],

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

		moveTo(parentId) {
			this.$emit('moveTag', this.tag.id, parentId)
		},

		onDragStart(event) {
			event.stopPropagation()
			event.dataTransfer.setData(DRAG_TYPE_TAG, String(this.tag.id))
			event.dataTransfer.effectAllowed = 'move'
		},

		onDragOver(event) {
			const types = event.dataTransfer.types
			if (this.writable && (types.includes(DRAG_TYPE_NOTE) || types.includes(DRAG_TYPE_TAG))) {
				event.preventDefault()
				event.stopPropagation()
				event.dataTransfer.dropEffect = types.includes(DRAG_TYPE_TAG) ? 'move' : 'link'
				this.dropTarget = true
			}
		},

		onDrop(event) {
			this.dropTarget = false
			const noteIds = decodeNoteIds(event.dataTransfer.getData(DRAG_TYPE_NOTE))
			const tagId = Number(event.dataTransfer.getData(DRAG_TYPE_TAG))
			if (noteIds.length === 0 && !tagId) {
				return
			}
			event.preventDefault()
			event.stopPropagation()

			if (noteIds.length > 0) {
				this.$emit('linkNotes', noteIds, this.tag.id)
			} else {
				// The dropped tag becomes a child tag of this tag
				this.$emit('moveTag', tagId, this.tag.id)
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
