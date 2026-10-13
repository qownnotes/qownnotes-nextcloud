/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { LanguageDescription } from '@codemirror/language'
import { languages } from '@codemirror/language-data'
import { highlightCode, tagHighlighter, tags } from '@lezer/highlight'

// Tags of the Markdown syntax (atom, meta, escape, character, …) are styled by markdownHighlightStyle.
// The same CSS classes are used by the editor and the preview, their colors are defined in src/css/codeHighlighting.css
export const codeHighlighter = tagHighlighter([
	{
		tag: [tags.keyword, tags.controlKeyword, tags.definitionKeyword, tags.moduleKeyword, tags.operatorKeyword, tags.modifier, tags.self],
		class: 'qn-code-keyword',
	},
	{ tag: [tags.string, tags.special(tags.string), tags.docString, tags.attributeValue], class: 'qn-code-string' },
	{ tag: tags.regexp, class: 'qn-code-regexp' },
	{ tag: [tags.number, tags.integer, tags.float, tags.bool, tags.null, tags.unit, tags.constant(tags.variableName)], class: 'qn-code-number' },
	{ tag: [tags.comment, tags.lineComment, tags.blockComment, tags.docComment], class: 'qn-code-comment' },
	{ tag: [tags.function(tags.variableName), tags.function(tags.propertyName), tags.macroName], class: 'qn-code-function' },
	{ tag: [tags.typeName, tags.className, tags.namespace, tags.standard(tags.variableName)], class: 'qn-code-type' },
	{ tag: [tags.propertyName, tags.attributeName], class: 'qn-code-property' },
	{ tag: [tags.tagName, tags.angleBracket], class: 'qn-code-tag' },
	{ tag: tags.annotation, class: 'qn-code-meta' },
	{ tag: [tags.inserted], class: 'qn-code-inserted' },
	{ tag: [tags.deleted], class: 'qn-code-deleted' },
	{ tag: tags.invalid, class: 'qn-code-invalid' },
])

const loading = new Map()
// Languages whose parser could not be loaded, their code is not highlighted
const failed = new Set()

/**
 * The language of a fenced code block, by its info string ("js", "python title=x", …)
 *
 * @param {string} info the info string of the code block
 * @return {LanguageDescription|null}
 */
export function findCodeLanguage(info) {
	const name = info.trim().split(/\s+/u, 1)[0]
	return name === '' ? null : LanguageDescription.matchLanguageName(languages, name, true)
}

/**
 * Loads the parser of a language, which is needed before its code can be highlighted
 *
 * @param {LanguageDescription} language the language
 * @return {Promise<void>}
 */
export function loadCodeLanguage(language) {
	if (!loading.has(language)) {
		loading.set(language, language.load().then(() => undefined, () => {
			failed.add(language)
		}))
	}
	return loading.get(language)
}

/**
 * @param {string} text the text
 * @return {string}
 */
function escapeHtml(text) {
	return text.replace(/[&<>"]/gu, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[character])
}

/**
 * Highlights code as HTML with spans of the classes of codeHighlighter
 *
 * @param {string} code the code
 * @param {string} info the info string of the code block
 * @return {{html: string|null, pending: LanguageDescription|null}} the HTML, or null if the language is unknown
 *   or still has to be loaded (`pending`, see loadCodeLanguage)
 */
export function highlightCodeToHtml(code, info) {
	const language = findCodeLanguage(info)
	if (!language || failed.has(language)) {
		return { html: null, pending: null }
	}
	if (!language.support) {
		return { html: null, pending: language }
	}

	let html = ''
	highlightCode(
		code,
		language.support.language.parser.parse(code),
		codeHighlighter,
		(text, classes) => {
			html += classes ? `<span class="${classes}">${escapeHtml(text)}</span>` : escapeHtml(text)
		},
		() => {
			html += '\n'
		},
	)
	return { html, pending: null }
}
