/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/stores/settings.js'

vi.mock('../../src/api.js', () => ({}))

describe('browser settings', () => {
	beforeEach(() => {
		window.localStorage.clear()
		setActivePinia(createPinia())
	})

	it('keeps the existing editor-only layout by default', () => {
		const settings = useSettingsStore()
		expect(settings.local.preview).toBe(false)
		expect(settings.local.sideBySidePreview).toBe(false)
	})

	it('adds the split preview preference to existing browser settings', () => {
		window.localStorage.setItem('qownnotes-web-settings', JSON.stringify({ preview: true, sortOrder: 'title' }))
		const settings = useSettingsStore()
		expect(settings.local.preview).toBe(true)
		expect(settings.local.sortOrder).toBe('title')
		expect(settings.local.sideBySidePreview).toBe(false)
	})

	it('remembers the split layout independently of preview visibility', () => {
		const settings = useSettingsStore()
		settings.setLocal('sideBySidePreview', true)
		settings.setLocal('preview', true)
		settings.setLocal('preview', false)
		setActivePinia(createPinia())
		expect(useSettingsStore().local.sideBySidePreview).toBe(true)
		expect(useSettingsStore().local.preview).toBe(false)
	})
})
