<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div ref="container" class="markdown-editor" />
</template>

<script>
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language'
import { highlightSelectionMatches, search, searchKeymap } from '@codemirror/search'
import { Compartment, EditorState } from '@codemirror/state'
import { drawSelection, EditorView, highlightActiveLine, keymap, placeholder } from '@codemirror/view'
import { tags } from '@lezer/highlight'

// Markdown highlighting similar to QOwnNotes Desktop, with the colors of the Nextcloud theme
const highlightStyle = HighlightStyle.define([
	{ tag: tags.heading1, fontWeight: 'bold', fontSize: '1.6em' },
	{ tag: tags.heading2, fontWeight: 'bold', fontSize: '1.4em' },
	{ tag: tags.heading3, fontWeight: 'bold', fontSize: '1.2em' },
	{ tag: [tags.heading4, tags.heading5, tags.heading6], fontWeight: 'bold' },
	{ tag: tags.strong, fontWeight: 'bold' },
	{ tag: tags.emphasis, fontStyle: 'italic' },
	{ tag: tags.strikethrough, textDecoration: 'line-through' },
	{ tag: [tags.link, tags.url], color: 'var(--color-primary-element)' },
	{ tag: tags.monospace, fontFamily: 'var(--font-face-monospace, monospace)', color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.quote, fontStyle: 'italic', color: 'var(--color-text-maxcontrast)' },
	{ tag: [tags.processingInstruction, tags.meta, tags.contentSeparator], color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.list, color: 'var(--color-primary-element)' },
])

const theme = EditorView.theme({
	'&': {
		height: '100%',
		fontSize: 'var(--default-font-size)',
		backgroundColor: 'var(--color-main-background)',
		color: 'var(--color-main-text)',
	},
	'.cm-scroller': {
		fontFamily: 'var(--font-face)',
		lineHeight: '1.6',
	},
	'.cm-content': {
		padding: '16px 0',
		caretColor: 'var(--color-main-text)',
	},
	'.cm-line': {
		padding: '0 4px',
	},
	'&.cm-focused': {
		outline: 'none',
	},
	'.cm-activeLine': {
		backgroundColor: 'var(--color-background-hover)',
	},
	'&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection': {
		backgroundColor: 'var(--color-primary-element-light) !important',
	},
	'.cm-panels': {
		backgroundColor: 'var(--color-main-background)',
		color: 'var(--color-main-text)',
	},
})

export default {
	name: 'MarkdownEditor',

	props: {
		modelValue: {
			type: String,
			required: true,
		},

		readonly: {
			type: Boolean,
			default: false,
		},

		placeholder: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	watch: {
		modelValue(value) {
			// Changes from outside, e.g. when the newer text of another client is taken over
			if (this.view && value !== this.view.state.doc.toString()) {
				this.view.dispatch({ changes: { from: 0, to: this.view.state.doc.length, insert: value } })
			}
		},

		readonly(value) {
			this.view?.dispatch({ effects: this.readonlyCompartment.reconfigure(this.readonlyExtensions(value)) })
		},
	},

	created() {
		// Not reactive: CodeMirror manages its own state
		this.view = null
		this.readonlyCompartment = new Compartment()
	},

	mounted() {
		this.view = new EditorView({
			parent: this.$refs.container,
			state: EditorState.create({
				doc: this.modelValue,
				extensions: [
					history(),
					drawSelection(),
					highlightActiveLine(),
					highlightSelectionMatches(),
					search({ top: true }),
					keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap, indentWithTab]),
					markdown({ base: markdownLanguage }),
					syntaxHighlighting(highlightStyle),
					EditorView.lineWrapping,
					placeholder(this.placeholder),
					theme,
					this.readonlyCompartment.of(this.readonlyExtensions(this.readonly)),
					EditorView.updateListener.of((update) => {
						if (update.docChanged) {
							this.$emit('update:modelValue', update.state.doc.toString())
						}
					}),
				],
			}),
		})
	},

	beforeUnmount() {
		this.view?.destroy()
	},

	methods: {
		readonlyExtensions(readonly) {
			return [EditorState.readOnly.of(readonly), EditorView.editable.of(!readonly)]
		},

		focus() {
			this.view?.focus()
		},

		/**
		 * Inserts text at the cursor, replacing the selection
		 *
		 * @param {string} text the text
		 */
		insertText(text) {
			if (!this.view) {
				return
			}
			this.view.dispatch(this.view.state.replaceSelection(text))
			this.view.focus()
		},
	},
}
</script>

<style scoped>
.markdown-editor {
	height: 100%;
}

/* Nextcloud styles contenteditable elements like input fields */
.markdown-editor :deep(.cm-content) {
	width: auto;
	min-height: auto;
	margin: 0;
	border: none;
	border-radius: 0;
	box-shadow: none;
	background-color: transparent;
}
</style>
