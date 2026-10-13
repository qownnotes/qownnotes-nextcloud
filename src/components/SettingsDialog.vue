<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppSettingsDialog
		:open="open"
		:name="t('qownnotes', 'QOwnNotes settings')"
		class="settings-dialog"
		showNavigation
		@update:open="onOpenChanged">
		<NcAppSettingsSection id="qownnotes-settings-folder" :name="t('qownnotes', 'Note folder')">
			<div class="settings-dialog__fields">
				<NcTextField
					v-model="form.notesPath"
					:label="t('qownnotes', 'Path of the note folder')"
					:helperText="t('qownnotes', 'Relative to your files, used by QOwnNotes Android and the web interface')" />
				<NcTextField
					v-model="form.fileSuffix"
					:label="t('qownnotes', 'File extension of new notes')"
					:helperText="t('qownnotes', 'For example .md or .txt')" />
			</div>
			<fieldset class="settings-dialog__radios">
				<legend>{{ t('qownnotes', 'Title style for new notes') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.noteHeaderStyle"
					value="atx"
					name="noteHeaderStyle"
					type="radio">
					{{ t('qownnotes', 'New notes start with a "# Title" headline') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.noteHeaderStyle"
					value="setext"
					name="noteHeaderStyle"
					type="radio">
					{{ t('qownnotes', 'New notes start with an underlined title') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="qownnotes-settings-subfolders" :name="t('qownnotes', 'Subfolders')">
			<NcCheckboxRadioSwitch v-model="form.subfoldersEnabled" type="switch">
				{{ t('qownnotes', 'Use note subfolders') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="form.ignoreNoteSubFolders"
				:disabled="!form.subfoldersEnabled"
				:label="t('qownnotes', 'Ignored subfolders')"
				:helperText="t('qownnotes', 'Regular expressions, separated by ;, like in QOwnNotes Desktop')" />
			<NcCheckboxRadioSwitch v-model="form.rewriteMediaLinks" type="switch">
				{{ t('qownnotes', 'Adapt links to media files and attachments when moving notes') }}
			</NcCheckboxRadioSwitch>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="qownnotes-settings-tags" :name="t('qownnotes', 'Tags')">
			<NcCheckboxRadioSwitch v-model="form.createTagDatabase" type="switch">
				{{ t('qownnotes', 'Create the note folder database "notes.sqlite" for the first tag') }}
			</NcCheckboxRadioSwitch>
			<p v-if="tagsStore.available && tagsStore.schemaVersion" class="settings-hint">
				{{ t('qownnotes', 'Database version: {version}', { version: tagsStore.schemaVersion }) }}
				<template v-if="!tagsStore.writable">
					{{ t('qownnotes', 'This version is not supported for changes, so tags are read-only. Please update the app.') }}
				</template>
			</p>
			<p v-else-if="!tagsStore.available" class="settings-hint">
				{{ t('qownnotes', 'Tags are not available, because the server is missing the PHP extension "pdo_sqlite".') }}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="qownnotes-settings-editor" :name="t('qownnotes', 'Editor')">
			<NcCheckboxRadioSwitch v-model="sideBySidePreview" type="switch">
				{{ t('qownnotes', 'Show preview beside the editor') }}
			</NcCheckboxRadioSwitch>
			<p class="settings-hint">
				{{ t('qownnotes', 'The preview updates as you type. This setting is saved in this browser only.') }}
			</p>
		</NcAppSettingsSection>

		<div class="settings-dialog__buttons">
			<NcButton :disabled="saving" @click="onOpenChanged(false)">
				{{ t('qownnotes', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ saving ? t('qownnotes', 'Saving …') : t('qownnotes', 'Save') }}
			</NcButton>
		</div>
	</NcAppSettingsDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../api.js'
import { useSettingsStore } from '../stores/settings.js'
import { useTagsStore } from '../stores/tags.js'

export default {
	name: 'SettingsDialog',

	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'changed'],

	data() {
		return {
			form: {},
			sideBySidePreview: false,
			saving: false,
		}
	},

	computed: {
		...mapStores(useSettingsStore, useTagsStore),
	},

	watch: {
		open: {
			immediate: true,
			handler(open) {
				if (open) {
					this.form = { ...this.settingsStore.server }
					this.sideBySidePreview = this.settingsStore.local.sideBySidePreview
				}
			},
		},
	},

	methods: {
		t,

		onOpenChanged(open) {
			this.$emit('update:open', open)
		},

		async save() {
			this.saving = true
			try {
				await this.settingsStore.save(this.form)
				if (this.sideBySidePreview && !this.settingsStore.local.sideBySidePreview) {
					this.settingsStore.setLocal('preview', true)
				}
				this.settingsStore.setLocal('sideBySidePreview', this.sideBySidePreview)
				showSuccess(t('qownnotes', 'Settings saved'))
				this.$emit('update:open', false)
				this.$emit('changed')
			} catch (error) {
				showError(t('qownnotes', 'The settings could not be saved: {message}', { message: errorMessage(error) }))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.settings-dialog :deep(.app-settings-section) {
	--app-settings-section-content-gap: 16px;
}

.settings-dialog__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.settings-dialog__radios {
	min-width: 0;
	margin: 0;
	padding: 0;
	border: 0;
}

.settings-dialog__radios legend {
	margin-bottom: 8px;
	padding-inline: var(--app-settings-section-text-offset);
	font-weight: bold;
}

.settings-hint {
	margin: 0;
	padding-inline: var(--app-settings-section-text-offset);
	color: var(--color-text-maxcontrast);
	line-height: 1.6;
	overflow-wrap: anywhere;
}

.settings-dialog__buttons {
	position: sticky;
	bottom: 0;
	z-index: 1;
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}
</style>
