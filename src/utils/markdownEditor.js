/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { ensureSyntaxTree, HighlightStyle, syntaxHighlighting } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
import { EditorSelection } from '@codemirror/state'
import { tags } from '@lezer/highlight'

// Use Nextcloud's theme colors so highlighting also works in dark mode.
export const markdownHighlightStyle = HighlightStyle.define([
	{ tag: tags.heading1, fontWeight: 'bold', fontSize: '1.6em', color: 'var(--color-primary-element)' },
	{ tag: tags.heading2, fontWeight: 'bold', fontSize: '1.4em', color: 'var(--color-primary-element)' },
	{ tag: tags.heading3, fontWeight: 'bold', fontSize: '1.2em', color: 'var(--color-primary-element)' },
	{ tag: [tags.heading4, tags.heading5, tags.heading6], fontWeight: 'bold', color: 'var(--color-primary-element)' },
	{ tag: tags.strong, fontWeight: 'bold' },
	{ tag: tags.emphasis, fontStyle: 'italic' },
	{ tag: tags.strikethrough, textDecoration: 'line-through' },
	{ tag: [tags.link, tags.url], color: 'var(--color-primary-element)', textDecoration: 'underline' },
	{ tag: tags.monospace, fontFamily: 'var(--font-face-monospace, monospace)', backgroundColor: 'var(--color-background-dark)' },
	{ tag: tags.quote, fontStyle: 'italic', color: 'var(--color-text-maxcontrast)' },
	{ tag: [tags.processingInstruction, tags.contentSeparator], color: 'var(--color-primary-element)' },
	{ tag: [tags.labelName, tags.atom, tags.meta], color: 'var(--color-primary-element)' },
	{ tag: [tags.escape, tags.character], color: 'var(--color-primary-element)' },
	{ tag: tags.comment, color: 'var(--color-text-maxcontrast)', fontStyle: 'italic' },
	{ tag: [tags.keyword, tags.operator], color: 'var(--color-primary-element)', fontWeight: 'bold' },
	{ tag: [tags.string, tags.regexp], color: 'var(--color-success-text, var(--color-success))' },
	{ tag: [tags.number, tags.bool, tags.null], color: 'var(--color-warning-text, var(--color-primary-element))' },
	{ tag: [tags.typeName, tags.className, tags.function(tags.variableName)], color: 'var(--color-primary-element)' },
	{ tag: tags.invalid, color: 'var(--color-error-text, var(--color-error))' },
])

/**
 * Markdown and lazily loaded syntax highlighting for fenced code languages.
 *
 * @return {Array} CodeMirror extensions
 */
export function markdownEditorHighlighting() {
	return [
		markdown({ base: markdownLanguage, codeLanguages: languages }),
		syntaxHighlighting(markdownHighlightStyle),
	]
}

/**
 * Inserts an unchecked task above a task when the cursor precedes its text.
 * Returns false elsewhere so the normal Markdown Enter command can run.
 *
 * @param {import('@codemirror/view').EditorView} view editor view
 * @return {boolean} whether a task was inserted
 */
export function insertTaskAbove(view) {
	const { state } = view
	if (state.readOnly || state.selection.ranges.some((range) => !range.empty)) {
		return false
	}
	const end = Math.max(...state.selection.ranges.map((range) => state.doc.lineAt(range.head).to))
	const tree = ensureSyntaxTree(state, end, 50)
	if (!tree) {
		return false
	}

	const prefixes = new Map()
	for (const range of state.selection.ranges) {
		const line = state.doc.lineAt(range.head)
		let prefix
		tree.iterate({
			from: line.from,
			to: line.to,
			enter(node) {
				if (node.name !== 'TaskMarker' || node.from < line.from || node.to > line.to) {
					return
				}
				const textStart = node.to + /^\s*/.exec(state.doc.sliceString(node.to, line.to))[0].length
				if (range.head <= textStart && textStart < line.to) {
					prefix = state.doc.sliceString(line.from, node.from) + '[ ]' + state.doc.sliceString(node.to, textStart)
				}
			},
		})
		if (!prefix || prefixes.has(line.from)) {
			return false
		}
		prefixes.set(line.from, prefix)
	}

	view.dispatch(state.update(state.changeByRange((range) => {
		const line = state.doc.lineAt(range.head)
		const prefix = prefixes.get(line.from)
		return {
			changes: { from: line.from, insert: prefix + state.lineBreak },
			range: EditorSelection.cursor(line.from + prefix.length),
		}
	}), { scrollIntoView: true, userEvent: 'input' }))
	return true
}

/**
 * Toggles the task checkbox on each cursor's line without moving the selection.
 * Only parsed Markdown tasks are changed, not lookalikes inside code blocks.
 *
 * @param {import('@codemirror/view').EditorView} view editor view
 * @return {boolean} whether a checkbox was toggled
 */
export function toggleTaskCheckbox(view) {
	const { state } = view
	if (state.readOnly) {
		return false
	}

	const lines = new Map(state.selection.ranges.map((range) => {
		const line = state.doc.lineAt(range.head)
		return [line.number, line]
	}))
	const end = Math.max(...Array.from(lines.values(), (line) => line.to))
	const tree = ensureSyntaxTree(state, end, 50)
	if (!tree) {
		return false
	}

	const changes = []
	for (const line of lines.values()) {
		tree.iterate({
			from: line.from,
			to: line.to,
			enter(node) {
				if (node.name === 'TaskMarker' && node.from >= line.from && node.to <= line.to) {
					const from = node.from + 1
					changes.push({ from, to: from + 1, insert: state.doc.sliceString(from, from + 1) === ' ' ? 'x' : ' ' })
				}
			},
		})
	}
	if (!changes.length) {
		return false
	}

	view.dispatch({ changes: changes.sort((a, b) => a.from - b.from), userEvent: 'input.toggleTask' })
	return true
}
