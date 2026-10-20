/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import LinkDialog from '../../src/components/LinkDialog.vue'
import { fetchLinkTitle } from '../../src/api.js'

vi.mock('../../src/api.js', () => ({ fetchLinkTitle: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: { render() { return h('form', { onSubmit: (event) => this.$emit('submit', event) }, [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { props: ['pressed'], render() { return h('button', { type: 'button', 'aria-pressed': this.pressed }, this.$slots.default?.()) } },
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() { return h('input', { value: this.modelValue, 'aria-label': this.label, onInput: (event) => this.$emit('update:modelValue', event.target.value) }) },
	},
}))

let app
let container
let readClipboard

beforeEach(() => {
	vi.useFakeTimers()
	vi.mocked(fetchLinkTitle).mockReset()
	readClipboard = vi.fn().mockResolvedValue('')
	vi.stubGlobal('navigator', { clipboard: { readText: readClipboard } })
})

afterEach(() => {
	app?.unmount()
	container?.remove()
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

/**
 * Mounts the actual dialog with lightweight Nextcloud component wrappers.
 *
 * @param {object} props dialog props and event listeners
 * @return {object} dialog instance
 */
function mountDialog(props = {}) {
	container = document.createElement('div')
	document.body.append(container)
	app = createApp({ render: () => h(LinkDialog, { notes: [], noteId: 1, ...props }) })
	return app.mount(container).$.subTree.component.proxy
}

it('prefills clipboard text and automatically fetches a web title', async () => {
	readClipboard.mockResolvedValue(' https://example.com\n')
	vi.mocked(fetchLinkTitle).mockResolvedValue('Clipboard title')
	const dialog = mountDialog()
	await vi.advanceTimersByTimeAsync(600)
	expect(dialog.url).toBe('https://example.com')
	expect(dialog.title).toBe('Clipboard title')
})

it('keeps selected link text when prefilling the clipboard URL', async () => {
	readClipboard.mockResolvedValue('https://example.com')
	const dialog = mountDialog({ selection: 'Selected text' })
	await vi.advanceTimersByTimeAsync(600)
	expect(dialog.url).toBe('https://example.com')
	expect(dialog.title).toBe('Selected text')
	expect(fetchLinkTitle).not.toHaveBeenCalled()
})

it('does not read the clipboard for a selected URL', async () => {
	const dialog = mountDialog({ selection: 'https://selected.example' })
	await nextTick()
	expect(dialog.url).toBe('https://selected.example')
	expect(readClipboard).not.toHaveBeenCalled()
})

it('allows manual entry when clipboard access is denied', async () => {
	readClipboard.mockRejectedValue(new Error('Permission denied'))
	const dialog = mountDialog()
	await nextTick()
	expect(dialog.url).toBe('')
	dialog.url = 'https://example.com'
	expect(dialog.canInsert).toBe(true)
})

it('does not overwrite manual entry with a delayed clipboard response', async () => {
	let resolve
	readClipboard.mockImplementation(() => new Promise((done) => {
		resolve = done
	}))
	const dialog = mountDialog()
	dialog.url = 'https://manual.example'
	resolve('https://clipboard.example')
	await nextTick()
	expect(dialog.url).toBe('https://manual.example')
})

it('ignores clipboard responses after the dialog is unmounted', async () => {
	let resolve
	readClipboard.mockImplementation(() => new Promise((done) => {
		resolve = done
	}))
	const dialog = mountDialog()
	app.unmount()
	app = null
	resolve('https://clipboard.example')
	await vi.advanceTimersByTimeAsync(600)
	expect(dialog.url).toBe('')
	expect(fetchLinkTitle).not.toHaveBeenCalled()
})

it('closes immediately on Escape from a text field and removes the listener on unmount', () => {
	const close = vi.fn()
	mountDialog({ onClose: close })
	const input = container.querySelector('input')
	input.focus()
	const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
	input.dispatchEvent(event)
	expect(close).toHaveBeenCalledOnce()
	expect(event.defaultPrevented).toBe(true)
	app.unmount()
	app = null
	document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
	expect(close).toHaveBeenCalledOnce()
})

it('prefills a selected URL, fetches its title and inserts the link', async () => {
	vi.mocked(fetchLinkTitle).mockResolvedValue('Fetched title')
	const insert = vi.fn()
	const close = vi.fn()
	const dialog = mountDialog({ selection: 'https://example.com', onInsert: insert, onClose: close })
	expect(dialog.url).toBe('https://example.com')
	await vi.advanceTimersByTimeAsync(600)
	expect(fetchLinkTitle).toHaveBeenCalledWith('https://example.com/', expect.any(AbortSignal))
	expect(dialog.title).toBe('Fetched title')
	dialog.insert()
	expect(insert).toHaveBeenCalledWith('[Fetched title](https://example.com/)')
	expect(close).toHaveBeenCalledOnce()
})

it('opens the note tab directly without fetching a selected web URL', async () => {
	const dialog = mountDialog({ initialTab: 'note', selection: 'https://example.com' })
	expect(dialog.tab).toBe('note')
	expect(readClipboard).not.toHaveBeenCalled()
	await vi.advanceTimersByTimeAsync(600)
	expect(fetchLinkTitle).not.toHaveBeenCalled()
	expect(dialog.canInsert).toBe(false)
})

it('preserves selected link text and fetches only on explicit request', async () => {
	vi.mocked(fetchLinkTitle).mockResolvedValue('Fetched title')
	const dialog = mountDialog({ selection: 'Selected text' })
	dialog.url = 'https://example.com'
	await nextTick()
	await vi.advanceTimersByTimeAsync(600)
	expect(fetchLinkTitle).not.toHaveBeenCalled()
	expect(dialog.title).toBe('Selected text')
	await dialog.fetchTitle()
	expect(dialog.title).toBe('Fetched title')
})

it('does not replace text edited while fetching', async () => {
	let resolve
	vi.mocked(fetchLinkTitle).mockImplementation(() => new Promise((done) => {
		resolve = done
	}))
	const dialog = mountDialog({ selection: 'https://example.com' })
	const request = dialog.fetchTitle()
	dialog.title = 'My own title'
	resolve('Fetched title')
	await request
	expect(dialog.title).toBe('My own title')
})

it('cancels stale requests and leaves manual insertion available after failure', async () => {
	let resolve
	vi.mocked(fetchLinkTitle).mockImplementation(() => new Promise((done) => {
		resolve = done
	}))
	const dialog = mountDialog({ selection: 'https://example.com' })
	const request = dialog.fetchTitle()
	const signal = vi.mocked(fetchLinkTitle).mock.calls[0][1]
	dialog.url = 'https://another.example'
	await nextTick()
	expect(signal.aborted).toBe(true)
	resolve('Old title')
	await request
	expect(dialog.title).toBe('')
	vi.mocked(fetchLinkTitle).mockRejectedValue(new Error('Unavailable'))
	await dialog.fetchTitle()
	expect(dialog.fetchError).toContain('could not be fetched')
	expect(dialog.canInsert).toBe(true)
})

it('searches notes across subfolders and keeps note text independent of web fetching', async () => {
	let resolve
	vi.mocked(fetchLinkTitle).mockImplementation(() => new Promise((done) => {
		resolve = done
	}))
	const insert = vi.fn()
	const target = { id: 2, title: 'Target note', category: 'Elsewhere', internalPath: 'Notes/Elsewhere/Target note.txt' }
	const dialog = mountDialog({ notes: [{ id: 1, title: 'Current' }, target], category: 'Work/Project', selection: 'https://example.com', onInsert: insert })
	const request = dialog.fetchTitle()
	dialog.tab = 'note'
	dialog.query = 'elsewhere'
	expect(dialog.matchingNotes).toEqual([target])
	dialog.selectNote(target)
	resolve('Web title')
	await request
	expect(dialog.title).toBe('Target note')
	dialog.insert()
	expect(insert).toHaveBeenCalledWith('[Target note](../../Elsewhere/Target%20note.txt)')
})

it('prevents inserting invalid URLs or an unselected note', () => {
	const insert = vi.fn()
	const dialog = mountDialog({ onInsert: insert })
	for (const url of ['javascript:alert(1)', 'file:///etc/passwd', 'https://user:password@example.com']) {
		dialog.url = url
		expect(dialog.canInsert).toBe(false)
		dialog.insert()
	}
	dialog.tab = 'note'
	dialog.insert()
	expect(insert).not.toHaveBeenCalled()
})
