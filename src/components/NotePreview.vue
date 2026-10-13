<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!-- eslint-disable vue/no-v-html -- sanitized by DOMPurify in renderMarkdown -->
	<div
		ref="root"
		class="note-preview"
		@click="onClick"
		@scroll.passive="onScroll"
		v-html="html" />
</template>

<script>
import { API_BASE } from '../api.js'
import { renderMarkdown } from '../utils/markdown.js'
import { lineOfPreviewOffset, previewAnchors, previewOffsetOfLine } from '../utils/scrollSync.js'

export default {
	name: 'NotePreview',

	props: {
		content: {
			type: String,
			required: true,
		},

		noteId: {
			type: Number,
			required: true,
		},

		readonly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggleTask', 'scroll', 'rendered'],

	data() {
		return {
			// Changed when the parsers of code block languages are loaded, to highlight the code blocks
			codeLanguagesLoaded: 0,
		}
	},

	computed: {
		html() {
			// Rendered again when the languages are loaded
			this.codeLanguagesLoaded
			return renderMarkdown(this.content, {
				apiBase: API_BASE,
				noteId: this.noteId,
				onCodeLanguagesLoaded: () => {
					this.codeLanguagesLoaded++
				},
			})
		},
	},

	created() {
		// The scroll position set by scrollToLine, its scroll event is not passed on
		this.expectedScrollTop = null
	},

	updated() {
		this.$emit('rendered')
	},

	methods: {
		/**
		 * The source line at the top of the preview
		 *
		 * @return {number} the line (0-based, with a fraction)
		 */
		scrollLine() {
			const { scrollTop, scrollHeight, clientHeight } = this.$refs.root
			const lineCount = this.content.split('\n').length
			if (scrollTop > 0 && scrollTop + clientHeight >= scrollHeight - 1) {
				return lineCount
			}
			return lineOfPreviewOffset(previewAnchors(this.$refs.root), scrollTop, lineCount, scrollHeight)
		},

		/**
		 * Scrolls a source line to the top of the preview
		 *
		 * @param {number} line the line (0-based, with a fraction), see scrollLine
		 */
		scrollToLine(line) {
			const lineCount = this.content.split('\n').length
			const root = this.$refs.root
			root.scrollTop = line >= lineCount
				? root.scrollHeight
				: previewOffsetOfLine(previewAnchors(root), line, lineCount, root.scrollHeight)
			this.expectedScrollTop = root.scrollTop
		},

		onScroll() {
			const expected = this.expectedScrollTop
			this.expectedScrollTop = null
			if (expected === null || Math.abs(this.$refs.root.scrollTop - expected) > 1) {
				this.$emit('scroll', this.scrollLine())
			}
		},

		onClick(event) {
			const target = event.target
			if (!(target instanceof HTMLInputElement) || !target.classList.contains('task-list-item-checkbox')) {
				return
			}
			event.preventDefault()
			if (!this.readonly) {
				const checkboxes = [...this.$refs.root.querySelectorAll('input.task-list-item-checkbox')]
				this.$emit('toggleTask', checkboxes.indexOf(target))
			}
		},
	},
}
</script>

<style scoped>
.note-preview {
	box-sizing: border-box;
	height: 100%;
	overflow: auto;
	padding: 16px 4px;
	line-height: 1.6;
	overflow-wrap: anywhere;
}

.note-preview :deep(h1),
.note-preview :deep(h2),
.note-preview :deep(h3),
.note-preview :deep(h4) {
	margin: 0.8em 0 0.4em;
	font-weight: bold;
}

.note-preview :deep(h1) {
	font-size: 1.6em;
}

.note-preview :deep(h2) {
	font-size: 1.4em;
}

.note-preview :deep(h3) {
	font-size: 1.2em;
}

.note-preview :deep(p),
.note-preview :deep(ul),
.note-preview :deep(ol),
.note-preview :deep(pre),
.note-preview :deep(blockquote),
.note-preview :deep(table) {
	margin-bottom: 0.8em;
}

.note-preview :deep(ul) {
	padding-inline-start: 1.5em;
	list-style: disc;
}

.note-preview :deep(ol) {
	padding-inline-start: 1.5em;
	list-style: decimal;
}

.note-preview :deep(.task-list-item) {
	list-style: none;
}

/* Nextcloud styles checkboxes like input fields (36px high) */
.note-preview :deep(.task-list-item-checkbox) {
	height: auto;
	min-height: auto;
	margin-block: 0;
	margin-inline: -1.4em 0.4em;
	vertical-align: middle;
	cursor: pointer;
}

.note-preview :deep(a) {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.note-preview :deep(img) {
	max-width: 100%;
}

.note-preview :deep(code),
.note-preview :deep(pre) {
	font-family: var(--font-face-monospace, monospace);
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.note-preview :deep(code) {
	padding: 0 4px;
}

.note-preview :deep(pre) {
	padding: 8px;
	overflow-x: auto;
}

.note-preview :deep(pre code) {
	padding: 0;
	background: none;
}

.note-preview :deep(blockquote) {
	padding-inline-start: 1em;
	border-inline-start: 4px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
}

.note-preview :deep(table) {
	border-collapse: collapse;
}

.note-preview :deep(th),
.note-preview :deep(td) {
	padding: 4px 8px;
	border: 1px solid var(--color-border-dark);
}
</style>
