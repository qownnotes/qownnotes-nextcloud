<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="t('qownnotes', 'Color of the tag \u201C{name}\u201D', { name: tag.name })"
		:buttons="buttons"
		class="tag-color-dialog"
		@update:open="$emit('close')">
		<p class="tag-color-dialog__hint">
			{{ t('qownnotes', 'Like in QOwnNotes Desktop, the tag can have another color in dark themes.') }}
		</p>
		<div v-for="field in fields" :key="field.key" class="tag-color-dialog__row">
			<span class="tag-color-dialog__label">{{ field.label }}</span>
			<NcColorPicker v-model="colors[field.key]" advancedFields>
				<NcButton :aria-label="field.label">
					<template #icon>
						<span
							class="tag-color-dialog__swatch"
							:class="{ 'tag-color-dialog__swatch--empty': !colors[field.key] }"
							:style="colors[field.key] ? { backgroundColor: colors[field.key] } : {}" />
					</template>
					{{ colors[field.key] || t('qownnotes', 'No color') }}
				</NcButton>
			</NcColorPicker>
			<NcButton
				v-if="colors[field.key]"
				variant="tertiary"
				:aria-label="t('qownnotes', 'Remove color ({label})', { label: field.label })"
				:title="t('qownnotes', 'Remove color')"
				@click="colors[field.key] = null">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'TagColorDialog',

	components: {
		Close,
		NcButton,
		NcColorPicker,
		NcDialog,
	},

	props: {
		tag: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			colors: {
				color: this.tag.color ?? null,
				darkColor: this.tag.darkColor ?? null,
			},
		}
	},

	computed: {
		fields() {
			return [
				{ key: 'color', label: t('qownnotes', 'Color') },
				{ key: 'darkColor', label: t('qownnotes', 'Color in dark themes') },
			]
		},

		buttons() {
			return [
				{ label: t('qownnotes', 'Cancel') },
				{
					label: t('qownnotes', 'Save'),
					variant: 'primary',
					callback: () => this.$emit('save', [{ op: 'update', id: this.tag.id, color: this.colors.color, darkColor: this.colors.darkColor }]),
				},
			]
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.tag-color-dialog :deep(.dialog__name) {
	flex-shrink: 0;
	line-height: 1.5;
	overflow-wrap: anywhere;
}

/* Long tag names wrap instead of running under the close button; on small screens NcDialog already
   aligns the name to the start and keeps space for the close button */
@media (min-width: 513px) {
	.tag-color-dialog :deep(.dialog__name) {
		padding-block: 8px;
		padding-inline: calc(var(--default-clickable-area, 44px) + 4px);
	}
}

.tag-color-dialog__hint {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
	line-height: 1.6;
}

.tag-color-dialog__row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto var(--default-clickable-area, 44px);
	align-items: center;
	gap: 8px;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
}

.tag-color-dialog__label {
	overflow-wrap: anywhere;
	font-weight: bold;
}

.tag-color-dialog__swatch {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 1px solid var(--color-border-dark);
	border-radius: 50%;
}

.tag-color-dialog__swatch--empty {
	background: repeating-linear-gradient(45deg, transparent 0 3px, var(--color-border-dark) 3px 4px);
}

@media (max-width: 480px) {
	.tag-color-dialog__row {
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.tag-color-dialog__label {
		grid-column: 1 / -1;
	}
}
</style>
