/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
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
