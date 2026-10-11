/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { syntaxTree } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
import { EditorState } from '@codemirror/state'
import { highlightTree, tags } from '@lezer/highlight'
import { describe, expect, it } from 'vitest'
import { markdownEditorHighlighting, markdownHighlightStyle } from '../../src/utils/markdownEditor.js'

/**
 * Returns highlighted text and classes from the actual editor parser.
 *
 * @param {string} doc Markdown text
 * @return {Array} highlighted spans
 */
function highlights(doc) {
	const state = EditorState.create({ doc, extensions: markdownEditorHighlighting() })
	const spans = []
	highlightTree(syntaxTree(state), markdownHighlightStyle, (from, to, classes) => {
		spans.push({ text: doc.slice(from, to), classes })
	})
	return spans
}

describe('Markdown editor highlighting', () => {
	it('highlights syntax markers, task boxes, code info, escapes and comments', () => {
		const spans = highlights('# Heading\n\n- [ ] task\n\n```unknown\nplain code\n```\n\n\\* &amp; <!-- comment -->')
		for (const text of ['#', '-', '```']) {
			expect(spans.some((span) => span.text === text && span.classes.split(' ').includes(markdownHighlightStyle.style([tags.processingInstruction])))).toBe(true)
		}
		for (const text of ['[ ]', 'unknown']) {
			expect(spans).toContainEqual({ text, classes: markdownHighlightStyle.style([tags.atom]) })
		}
		expect(spans).toContainEqual({ text: '\\*', classes: markdownHighlightStyle.style([tags.escape]) })
		expect(spans).toContainEqual({ text: '&amp;', classes: markdownHighlightStyle.style([tags.character]) })
		expect(spans).toContainEqual({ text: '<!-- comment -->', classes: markdownHighlightStyle.style([tags.comment]) })
	})

	it('highlights fenced JavaScript using the language alias', async () => {
		await languages.find((language) => language.name === 'JavaScript').load()
		const spans = highlights('```js\nconst answer = "hello"; // comment\nlet count = 42;\n```')
		expect(spans).toContainEqual({ text: 'const', classes: markdownHighlightStyle.style([tags.keyword]) })
		expect(spans).toContainEqual({ text: '"hello"', classes: markdownHighlightStyle.style([tags.string]) })
		expect(spans).toContainEqual({ text: '42', classes: markdownHighlightStyle.style([tags.number]) })
		expect(spans).toContainEqual({ text: '// comment', classes: markdownHighlightStyle.style([tags.comment]) })
	})

	it('retains headings and inline formatting while leaving ordinary text unstyled', () => {
		const spans = highlights('# Heading\n\n**bold** *italic* ~~deleted~~ `code` [link](https://example.com)\n\nordinary text')
		for (const text of ['Heading', 'bold', 'italic', 'deleted', 'code', 'link', 'https://example.com']) {
			expect(spans.some((span) => span.text.includes(text))).toBe(true)
		}
		expect(spans.some((span) => span.text.includes('ordinary text'))).toBe(false)
	})
})
