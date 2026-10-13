/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { renderMarkdown } from '../../src/utils/markdown.js'
import { lineOfPreviewOffset, previewAnchors, previewOffsetOfLine } from '../../src/utils/scrollSync.js'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))

const anchors = [{ line: 0, top: 0 }, { line: 2, top: 100 }, { line: 10, top: 300 }]

describe('scroll sync', () => {
	it('marks the source lines of the rendered blocks', () => {
		const html = renderMarkdown('# Title\n\ntext\n\n- item\n\n```js\ncode\n```', { apiBase: '/api', noteId: 1 })
		expect(html).toContain('<h1 data-source-line="0">')
		expect(html).toContain('<p data-source-line="2">')
		expect(html).toContain('<ul data-source-line="4">')
		expect(html).toMatch(/<code data-source-line="6"/u)
	})

	it('maps source lines to preview positions and back', () => {
		expect(previewOffsetOfLine(anchors, 0, 20, 500)).toBe(0)
		expect(previewOffsetOfLine(anchors, 1, 20, 500)).toBe(50)
		expect(previewOffsetOfLine(anchors, 6, 20, 500)).toBe(200)
		expect(previewOffsetOfLine(anchors, 15, 20, 500)).toBe(400)
		expect(previewOffsetOfLine(anchors, 25, 20, 500)).toBe(500)

		for (const line of [0, 1, 2.5, 6, 15]) {
			expect(lineOfPreviewOffset(anchors, previewOffsetOfLine(anchors, line, 20, 500), 20, 500)).toBeCloseTo(line)
		}
		expect(lineOfPreviewOffset(anchors, -10, 20, 500)).toBe(0)
		expect(lineOfPreviewOffset(anchors, 600, 20, 500)).toBe(20)
	})

	it('uses the first block of nested blocks that start at the same line', () => {
		const container = document.createElement('div')
		container.innerHTML = '<ul data-source-line="3"><li data-source-line="3">a</li><li data-source-line="5">b</li></ul>'
		const tops = { UL: 40, LI: 40 }
		for (const element of container.querySelectorAll('*')) {
			element.getBoundingClientRect = () => ({ top: element.dataset.sourceLine === '5' ? 80 : tops[element.tagName] })
		}
		container.getBoundingClientRect = () => ({ top: 0 })
		expect(previewAnchors(container)).toEqual([{ line: 0, top: 0 }, { line: 3, top: 40 }, { line: 5, top: 80 }])
	})
})
