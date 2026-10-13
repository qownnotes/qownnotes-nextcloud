/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { ensureSyntaxTree, HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
import { EditorSelection, RangeSetBuilder } from '@codemirror/state'
import { Decoration, EditorView, ViewPlugin } from '@codemirror/view'
import { tagHighlighter, tags } from '@lezer/highlight'
import { findAngleLinks } from './angleLinks.js'
import { codeHighlighter } from './codeHighlighting.js'

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
	{ tag: tags.monospace, fontFamily: 'var(--font-face-monospace, monospace)', backgroundColor: 'color-mix(in srgb, var(--color-main-text) 6%, transparent)' },
	{ tag: tags.quote, fontStyle: 'italic', color: 'var(--color-text-maxcontrast)' },
	{ tag: [tags.processingInstruction, tags.contentSeparator], color: 'var(--color-primary-element)' },
	{ tag: [tags.labelName, tags.atom, tags.meta], color: 'var(--color-primary-element)' },
	{ tag: [tags.escape, tags.character], color: 'var(--color-primary-element)' },
])

/**
 * Line decorations for code blocks, so they are shown in a monospace font on a background like in the preview
 *
 * @param {import('@codemirror/view').EditorView} view editor view
 * @return {import('@codemirror/view').DecorationSet}
 */
function codeBlockDecorations(view) {
	const builder = new RangeSetBuilder()
	const tree = syntaxTree(view.state)
	let last = -1
	for (const { from, to } of view.visibleRanges) {
		tree.iterate({
			from,
			to,
			enter(node) {
				if (node.name !== 'FencedCode' && node.name !== 'CodeBlock') {
					return
				}
				const first = view.state.doc.lineAt(node.from).number
				const end = view.state.doc.lineAt(node.to).number
				for (let number = Math.max(first, last + 1); number <= end; number++) {
					const line = view.state.doc.line(number)
					if (line.from > to) {
						break
					}
					const classes = ['cm-qn-code-block']
					if (number === first) {
						classes.push('cm-qn-code-block-first')
					}
					if (number === end) {
						classes.push('cm-qn-code-block-last')
					}
					builder.add(line.from, line.from, Decoration.line({ class: classes.join(' ') }))
					last = number
				}
				return false
			},
		})
	}
	return builder.finish()
}

const codeBlockPlugin = ViewPlugin.fromClass(class {
	constructor(view) {
		this.decorations = codeBlockDecorations(view)
	}

	update(update) {
		if (update.docChanged || update.viewportChanged || syntaxTree(update.startState) !== syntaxTree(update.state)) {
			this.decorations = codeBlockDecorations(update.view)
		}
	}
}, { decorations: (plugin) => plugin.decorations })

const CODE_NODES = new Set(['InlineCode', 'FencedCode', 'CodeBlock', 'HTMLBlock', 'CommentBlock', 'Comment'])

/**
 * The links like <Journal.md> of a line, without the ones in code or in Markdown links
 *
 * @param {import('@lezer/common').Tree} tree the syntax tree
 * @param {import('@codemirror/state').Line} line the line
 * @return {Array<{from: number, to: number, href: string}>} positions in the document
 */
function angleLinks(tree, line) {
	return findAngleLinks(line.text)
		.map((link) => ({ ...link, from: line.from + link.from, to: line.from + link.to }))
		.filter(({ from }) => {
			for (let node = tree.resolveInner(from, 1); node; node = node.parent) {
				if (CODE_NODES.has(node.name) || node.name === 'Link' || node.name === 'Image') {
					return false
				}
			}
			return true
		})
}

const angleLinkMark = Decoration.mark({ class: 'cm-qn-link cm-qn-angle-link' })

/**
 * @param {import('@codemirror/view').EditorView} view editor view
 * @return {import('@codemirror/view').DecorationSet}
 */
function angleLinkDecorations(view) {
	const builder = new RangeSetBuilder()
	const tree = syntaxTree(view.state)
	for (const { from, to } of view.visibleRanges) {
		for (let pos = from; pos <= to;) {
			const line = view.state.doc.lineAt(pos)
			for (const link of angleLinks(tree, line)) {
				builder.add(link.from, link.to, angleLinkMark)
			}
			pos = line.to + 1
		}
	}
	return builder.finish()
}

const angleLinkPlugin = ViewPlugin.fromClass(class {
	constructor(view) {
		this.decorations = angleLinkDecorations(view)
	}

	update(update) {
		if (update.docChanged || update.viewportChanged || syntaxTree(update.startState) !== syntaxTree(update.state)) {
			this.decorations = angleLinkDecorations(update.view)
		}
	}
}, { decorations: (plugin) => plugin.decorations })

/**
 * Markdown and lazily loaded syntax highlighting for fenced code languages.
 *
 * @return {Array} CodeMirror extensions
 */
export function markdownEditorHighlighting() {
	return [
		markdown({ base: markdownLanguage, codeLanguages: languages }),
		syntaxHighlighting(markdownHighlightStyle),
		syntaxHighlighting(codeHighlighter),
		codeBlockPlugin,
		angleLinkPlugin,
		EditorView.baseTheme({
			'.cm-qn-angle-link': { color: 'var(--color-primary-element)', textDecoration: 'underline' },
		}),
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

/**
 * The link at a position of the note text: the target of a Markdown link or image, an autolink or a plain URL
 *
 * @param {import('@codemirror/state').EditorState} state the editor state
 * @param {number} pos the position
 * @return {string|null}
 */
export function linkAt(state, pos) {
	const tree = ensureSyntaxTree(state, Math.min(state.doc.length, pos + 1), 50) ?? syntaxTree(state)
	for (const side of [1, -1]) {
		for (let node = tree.resolveInner(pos, side); node; node = node.parent) {
			const url = node.name === 'URL' ? node : null
			if (node.name === 'Link' || node.name === 'Image' || node.name === 'Autolink' || url) {
				const target = url ?? node.getChild('URL')
				if (!target) {
					return null
				}
				// Link destinations may be in angle brackets, e.g. [Note](<My note.md>)
				const href = state.sliceDoc(target.from, target.to).replace(/^<(.*)>$/u, '$1')
				const isEmail = (url?.parent ?? node).name === 'Autolink' && /^[^:/]+@/u.test(href)
				return isEmail ? `mailto:${href}` : href
			}
		}
	}
	const line = state.doc.lineAt(pos)
	const link = angleLinks(tree, line).find(({ from, to }) => from <= pos && pos <= to)
	return link?.href ?? null
}

const linkHighlighter = tagHighlighter([{ tag: [tags.link, tags.url], class: 'cm-qn-link' }])

/**
 * Opens links with Ctrl+click (Cmd+click on macOS) like QOwnNotes Desktop; while the key is held, links get a
 * pointer cursor
 *
 * @param {(href: string) => void} openLink called with the link
 * @return {Array} CodeMirror extensions
 */
export function linkClicking(openLink) {
	const isModifier = (event) => (/Mac/u.test(navigator.platform) ? event.metaKey : event.ctrlKey)
	const setModifier = (view, on) => view.dom.classList.toggle('cm-qn-modifier', on)
	return [
		syntaxHighlighting(linkHighlighter),
		EditorView.domEventHandlers({
			mousedown(event, view) {
				if (event.button !== 0 || !isModifier(event)) {
					return false
				}
				const pos = view.posAtCoords({ x: event.clientX, y: event.clientY })
				const href = pos === null ? null : linkAt(view.state, pos)
				if (href === null) {
					return false
				}
				event.preventDefault()
				openLink(href)
				return true
			},
			keydown(event, view) {
				setModifier(view, isModifier(event))
				return false
			},
			keyup(event, view) {
				setModifier(view, isModifier(event))
				return false
			},
			mousemove(event, view) {
				setModifier(view, isModifier(event))
				return false
			},
			blur(event, view) {
				setModifier(view, false)
				return false
			},
		}),
		EditorView.theme({
			'&.cm-qn-modifier .cm-qn-link, &.cm-qn-modifier .cm-qn-link *': {
				cursor: 'pointer',
			},
		}),
	]
}

/**
 * Opens the link at the cursor, see linkClicking
 *
 * @param {import('@codemirror/view').EditorView} view the editor view
 * @param {(href: string) => void} openLink called with the link
 * @return {boolean} whether there is a link at the cursor
 */
export function openLinkAtCursor(view, openLink) {
	const href = linkAt(view.state, view.state.selection.main.head)
	if (href === null) {
		return false
	}
	openLink(href)
	return true
}
