/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import DiffView from '../../src/components/DiffView.vue'

let app
let container

afterEach(() => {
	app?.unmount()
	container?.remove()
})

/**
 * @param {object} props diff source
 */
function mountDiff(props) {
	container = document.createElement('div')
	document.body.append(container)
	app = createApp({ render: () => h(DiffView, props) })
	app.mount(container)
	return container.querySelector('pre')
}

it('makes the scrollable text comparison keyboard accessible', () => {
	const preview = mountDiff({ from: 'Old text', to: 'New text' })
	expect(preview.tabIndex).toBe(0)
	preview.focus()
	expect(document.activeElement).toBe(preview)
	expect(preview.querySelector('del')).not.toBeNull()
	expect(preview.querySelector('ins')).not.toBeNull()
})

it('keeps server-rendered comparisons focusable and sanitized', () => {
	const preview = mountDiff({ html: '<ins onclick="alert(1)">Added</ins><del>Removed</del><img src=x onerror="alert(1)"><script>alert(1)</script>' })
	expect(preview.tabIndex).toBe(0)
	expect(preview.innerHTML).toBe('<ins>Added</ins><del>Removed</del>')
})
