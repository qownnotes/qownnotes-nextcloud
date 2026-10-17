/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Same rules as QOwnNotes Desktop (Note::extractLeadingEmoji, noteHeadline, Note::getNameWithoutLeadingEmoji
// and Note::isConflictedCopy), so notes get the same icons in the note list

export const CONFLICTED_COPY_EMOJI = '⚠️'

const CONFLICTED_COPY_REGEX = /\s\(conflicted copy \d{4}-\d{2}-\d{2} \d{6}\)(?:\.[^./\\]+)?$/u
const FRONTMATTER_REGEX = /^---(?:\r\n|\n\r|\r|\n).+?(?:\r\n|\n\r|\r|\n)---(?:\r\n|\n\r|\r|\n)/su
const HTML_COMMENT_REGEX = /^<!--.+?-->(?:\r\n|\n\r|\r|\n)/su
// Variation selectors, skin tone modifiers and zero-width joiner sequences that belong to an emoji
const EMOJI_TAIL_REGEX = /^(?:[\uFE0E\uFE0F]|[\u{1F3FB}-\u{1F3FF}]|\u200D\p{Extended_Pictographic}[\uFE0E\uFE0F]?)*/u

const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter
	? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
	: null

/**
 * @param {number} codePoint the code point
 * @return {boolean}
 */
function isEmojiCodePoint(codePoint) {
	return (codePoint >= 0x1F300 && codePoint <= 0x1FAFF)
		|| (codePoint >= 0x2702 && codePoint <= 0x27B0)
		|| (codePoint >= 0x1F100 && codePoint <= 0x1F1FF)
		|| (codePoint >= 0x2600 && codePoint <= 0x26FF)
}

/**
 * The emoji at the start of a text, or an empty string
 *
 * @param {string} text the text
 * @return {string}
 */
export function extractLeadingEmoji(text) {
	const value = String(text ?? '')
	if (value === '' || !isEmojiCodePoint(value.codePointAt(0))) {
		return ''
	}

	let emoji = segmenter
		? segmenter.segment(value)[Symbol.iterator]().next().value.segment
		: String.fromCodePoint(value.codePointAt(0))
	emoji += value.slice(emoji.length).match(EMOJI_TAIL_REGEX)[0]
	return emoji
}

/**
 * The first line of the note text without front matter, a leading HTML comment and a "# " heading marker
 *
 * @param {string} content the note text
 * @return {string}
 */
export function noteHeadline(content) {
	let text = String(content ?? '')
	if (text.startsWith('---')) {
		text = text.replace(FRONTMATTER_REGEX, '')
	} else if (text.startsWith('<!--')) {
		text = text.replace(HTML_COMMENT_REGEX, '')
	}

	text = text.replace(/^[\r\n]+/u, '')
	const firstLine = text.split(/[\r\n]/u, 1)[0] ?? ''
	return firstLine.trim().replace(/^#+\s/u, '')
}

/**
 * Whether the note is a sync conflict copy like "TODO (conflicted copy 2026-07-10 070952)"
 *
 * @param {object} note the note
 * @return {boolean}
 */
export function isConflictedCopy(note) {
	return CONFLICTED_COPY_REGEX.test(note?.title ?? '')
}

/**
 * The emoji icon and the name to show in the note list; `emoji` is empty if the regular note icon is shown
 *
 * @param {object} note the note with title and content
 * @param {boolean} detectLeadingEmoji whether the leading emoji of the note headline is used as icon
 * @return {{emoji: string, name: string}}
 */
export function noteListEntry(note, detectLeadingEmoji = true) {
	const title = note?.title ?? ''
	if (isConflictedCopy(note)) {
		return { emoji: CONFLICTED_COPY_EMOJI, name: title }
	}

	if (!detectLeadingEmoji) {
		return { emoji: '', name: title }
	}

	const headline = noteHeadline(note?.content)
	const emoji = extractLeadingEmoji(headline)
	if (emoji === '') {
		return { emoji: '', name: title }
	}

	// The emoji is taken from the headline, because QOwnNotes Desktop removes it from file names by default
	const name = headline.slice(emoji.length).trimStart()
	return { emoji, name: name === '' ? title : name }
}
