/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { syntaxTree } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
import { EditorState } from '@codemirror/state'
import { EditorView } from '@codemirror/view'
import { highlightTree, tags } from '@lezer/highlight'
import { describe, expect, it } from 'vitest'
import { codeHighlighter } from '../../src/utils/codeHighlighting.js'
import { markdownEditorHighlighting, markdownHighlightStyle } from '../../src/utils/markdownEditor.js'

/**
 * Returns highlighted text and classes from the actual editor parser.
 *
 * @param {string} doc Markdown text
 * @param {import('@lezer/highlight').Highlighter} highlighter the highlighter
 * @return {Array} highlighted spans
 */
function highlights(doc, highlighter = markdownHighlightStyle) {
	const state = EditorState.create({ doc, extensions: markdownEditorHighlighting() })
	const spans = []
	highlightTree(syntaxTree(state), highlighter, (from, to, classes) => {
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
		expect(highlights('<!-- comment -->', codeHighlighter)).toContainEqual({ text: '<!-- comment -->', classes: 'qn-code-comment' })
	})

	it('highlights fenced JavaScript using the language alias', async () => {
		await languages.find((language) => language.name === 'JavaScript').load()
		const spans = highlights('```js\nconst answer = "hello"; // comment\nlet count = 42;\nconsole.log(answer)\n```', codeHighlighter)
		expect(spans).toContainEqual({ text: 'const', classes: 'qn-code-keyword' })
		expect(spans).toContainEqual({ text: '"hello"', classes: 'qn-code-string' })
		expect(spans).toContainEqual({ text: '42', classes: 'qn-code-number' })
		expect(spans).toContainEqual({ text: '// comment', classes: 'qn-code-comment' })
		expect(spans).toContainEqual({ text: 'log', classes: 'qn-code-function' })
	})

	it('retains headings and inline formatting while leaving ordinary text unstyled', () => {
		const spans = highlights('# Heading\n\n**bold** *italic* ~~deleted~~ `code` [link](https://example.com)\n\nordinary text')
		for (const text of ['Heading', 'bold', 'italic', 'deleted', 'code', 'link', 'https://example.com']) {
			expect(spans.some((span) => span.text.includes(text))).toBe(true)
		}
		expect(spans.some((span) => span.text.includes('ordinary text'))).toBe(false)
	})

	it('marks the lines of code blocks', () => {
		const parent = document.createElement('div')
		document.body.append(parent)
		const view = new EditorView({
			parent,
			state: EditorState.create({ doc: 'text\n\n```\ncode\n```\n\n    indented', extensions: markdownEditorHighlighting() }),
		})
		const classes = [...parent.querySelectorAll('.cm-line')].map((line) => line.className.replace('cm-line', '').trim())
		expect(classes).toEqual([
			'',
			'',
			'cm-qn-code-block cm-qn-code-block-first',
			'cm-qn-code-block',
			'cm-qn-code-block cm-qn-code-block-last',
			'',
			'cm-qn-code-block cm-qn-code-block-first cm-qn-code-block-last',
		])
		view.destroy()
		parent.remove()
	})
})
