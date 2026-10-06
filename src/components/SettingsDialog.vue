<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcAppSettingsDialog
		:open="open"
		:name="t('qownnotes', 'QOwnNotes settings')"
		showNavigation
		@update:open="onOpenChanged">
		<NcAppSettingsSection id="qownnotes-settings-folder" :name="t('qownnotes', 'Note folder')">
			<NcTextField
				v-model="form.notesPath"
				:label="t('qownnotes', 'Path of the note folder')"
				:helperText="t('qownnotes', 'Relative to your files, used by QOwnNotes Android and the web interface')" />
			<NcTextField
				v-model="form.fileSuffix"
				:label="t('qownnotes', 'File extension of new notes')"
				:helperText="t('qownnotes', 'For example .md or .txt')" />
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
		</NcAppSettingsSection>

		<NcAppSettingsSection id="qownnotes-settings-subfolders" :name="t('qownnotes', 'Subfolders')">
			<NcCheckboxRadioSwitch v-model="form.subfoldersEnabled" type="switch">
				{{ t('qownnotes', 'Use note subfolders') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="form.ignoreNoteSubFolders"
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

		<div class="settings-dialog__buttons">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('qownnotes', 'Save') }}
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
.settings-dialog__buttons {
	display: flex;
	justify-content: flex-end;
	padding: 8px 0;
}
</style>
