/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'
import taskLists from 'markdown-it-task-lists'
import { attachmentUrl, isRelativeLink } from './mediaLinks.js'

const TASK_PATTERN = /^(\s*(?:[-*+]|\d+[.)])\s+\[)([ xX])(\])/u
const FENCE_PATTERN = /^\s*(`{3,}|~{3,})/u

const markdownIt = new MarkdownIt({ html: true, linkify: true, breaks: false })
	.use(taskLists, { enabled: true, label: true })

/**
 * Renders the note text to sanitized HTML; relative links to media files and attachments are served by the
 * attachment API
 *
 * @param {string} content the note text
 * @param {object} options the options
 * @param {string} options.apiBase base URL of the API
 * @param {number} options.noteId ID of the note
 * @return {string}
 */
export function renderMarkdown(content, { apiBase, noteId }) {
	const html = DOMPurify.sanitize(markdownIt.render(content), { ADD_ATTR: ['target'] })
	const template = document.createElement('template')
	template.innerHTML = html

	for (const image of template.content.querySelectorAll('img[src]')) {
		const src = image.getAttribute('src')
		if (isRelativeLink(src)) {
			image.setAttribute('src', attachmentUrl(apiBase, noteId, src))
		}
	}
	for (const link of template.content.querySelectorAll('a[href]')) {
		const href = link.getAttribute('href')
		if (isRelativeLink(href)) {
			link.setAttribute('href', attachmentUrl(apiBase, noteId, href))
		}
		if (!href.startsWith('#')) {
			link.setAttribute('target', '_blank')
			link.setAttribute('rel', 'noopener noreferrer')
		}
	}

	return template.innerHTML
}

/**
 * Toggles the checkbox of the n-th task list item of the note text; tasks in code blocks are skipped,
 * like markdown-it does
 *
 * @param {string} content the note text
 * @param {number} index index of the task, in the order of the rendered checkboxes
 * @return {string} the changed text, or the unchanged text if there is no such task
 */
export function toggleTask(content, index) {
	const lines = content.split('\n')
	let fence = null
	let count = 0
	for (let i = 0; i < lines.length; i++) {
		const fenceMatch = lines[i].match(FENCE_PATTERN)
		if (fenceMatch) {
			if (fence === null) {
				fence = fenceMatch[1][0]
			} else if (fenceMatch[1][0] === fence) {
				fence = null
			}
			continue
		}
		if (fence !== null || !TASK_PATTERN.test(lines[i])) {
			continue
		}
		if (count === index) {
			lines[i] = lines[i].replace(TASK_PATTERN, (match, start, mark, end) => start + (mark === ' ' ? 'x' : ' ') + end)
			return lines.join('\n')
		}
		count++
	}

	return content
}
