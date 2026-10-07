/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Texts with more tokens than this are compared line by line, to bound memory and time
const MAX_TOKENS = 2000

/**
 * Splits a text into words, whitespace and single other characters
 *
 * @param {string} text the text
 * @return {string[]}
 */
function words(text) {
	return text.match(/\s+|[\p{L}\p{N}_]+|./gsu) ?? []
}

/**
 * Splits a text into lines, keeping the line breaks
 *
 * @param {string} text the text
 * @return {string[]}
 */
function lines(text) {
	return text.match(/[^\n]*\n|[^\n]+$/gu) ?? []
}

/**
 * The differences between two texts, like DiffRenderer on the server
 *
 * @param {string} from the old text
 * @param {string} to the new text
 * @return {Array<{type: 'equal'|'delete'|'insert', text: string}>} consecutive parts of the same type are merged
 */
export function diffTexts(from, to) {
	let a = words(from)
	let b = words(to)
	if (a.length > MAX_TOKENS || b.length > MAX_TOKENS) {
		a = lines(from)
		b = lines(to)
	}

	// Common prefix and suffix don't need to be diffed
	let prefix = 0
	while (prefix < a.length && prefix < b.length && a[prefix] === b[prefix]) {
		prefix++
	}
	let suffix = 0
	while (suffix < a.length - prefix && suffix < b.length - prefix && a[a.length - 1 - suffix] === b[b.length - 1 - suffix]) {
		suffix++
	}

	const parts = [{ type: 'equal', text: a.slice(0, prefix).join('') }]
	const middleA = a.slice(prefix, a.length - suffix)
	const middleB = b.slice(prefix, b.length - suffix)
	if (middleA.length * middleB.length > MAX_TOKENS * MAX_TOKENS) {
		parts.push({ type: 'delete', text: middleA.join('') }, { type: 'insert', text: middleB.join('') })
	} else {
		parts.push(...lcsDiff(middleA, middleB))
	}
	parts.push({ type: 'equal', text: a.slice(a.length - suffix).join('') })

	return cleanup(parts.filter((part) => part.text !== ''))
}

/**
 * Merges consecutive changes into one deletion and one insertion; whitespace between changes becomes part of
 * them, so "a b" → "c d" shows as "a b" deleted and "c d" inserted instead of interleaved words
 *
 * @param {Array<{type: string, text: string}>} parts the parts
 * @return {Array<{type: string, text: string}>}
 */
function cleanup(parts) {
	const result = []
	let deleted = ''
	let inserted = ''
	const flush = () => {
		if (deleted !== '') {
			result.push({ type: 'delete', text: deleted })
		}
		if (inserted !== '') {
			result.push({ type: 'insert', text: inserted })
		}
		deleted = ''
		inserted = ''
	}

	parts.forEach((part, index) => {
		if (part.type === 'delete') {
			deleted += part.text
		} else if (part.type === 'insert') {
			inserted += part.text
		} else if ((deleted !== '' || inserted !== '') && /^\s+$/u.test(part.text) && index + 1 < parts.length && parts[index + 1].type !== 'equal') {
			deleted += part.text
			inserted += part.text
		} else {
			flush()
			const last = result.at(-1)
			if (last?.type === 'equal') {
				last.text += part.text
			} else {
				result.push({ ...part })
			}
		}
	})
	flush()
	return result
}

/**
 * @param {string[]} a old tokens
 * @param {string[]} b new tokens
 * @return {Array<{type: string, text: string}>}
 */
function lcsDiff(a, b) {
	// lengths[i][j]: length of the longest common subsequence of a[i..] and b[j..]
	const lengths = Array.from({ length: a.length + 1 }, () => new Uint32Array(b.length + 1))
	for (let i = a.length - 1; i >= 0; i--) {
		for (let j = b.length - 1; j >= 0; j--) {
			lengths[i][j] = a[i] === b[j] ? lengths[i + 1][j + 1] + 1 : Math.max(lengths[i + 1][j], lengths[i][j + 1])
		}
	}

	const parts = []
	let i = 0
	let j = 0
	while (i < a.length && j < b.length) {
		if (a[i] === b[j]) {
			parts.push({ type: 'equal', text: a[i++] })
			j++
		} else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
			parts.push({ type: 'delete', text: a[i++] })
		} else {
			parts.push({ type: 'insert', text: b[j++] })
		}
	}
	while (i < a.length) {
		parts.push({ type: 'delete', text: a[i++] })
	}
	while (j < b.length) {
		parts.push({ type: 'insert', text: b[j++] })
	}
	return parts
}
