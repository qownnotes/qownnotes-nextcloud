<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div ref="container" class="markdown-editor" />
</template>

<script>
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { highlightSelectionMatches, search, searchKeymap } from '@codemirror/search'
import { Compartment, EditorState, Prec } from '@codemirror/state'
import { drawSelection, EditorView, highlightActiveLine, keymap, placeholder } from '@codemirror/view'
import { insertTaskAbove, linkClicking, markdownEditorHighlighting, openLinkAtCursor, toggleTaskCheckbox } from '../utils/markdownEditor.js'
import { createSearchPanel, searchPanelTheme } from '../utils/searchPanel.js'

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
	'.cm-line.cm-qn-code-block': {
		padding: '0 8px',
		fontFamily: 'var(--font-face-monospace, monospace)',
		backgroundColor: 'var(--color-background-dark)',
	},
	'.cm-line.cm-qn-code-block.cm-activeLine': {
		backgroundColor: 'var(--color-background-darker)',
	},
	'.cm-qn-code-block-first': {
		borderStartStartRadius: 'var(--border-radius)',
		borderStartEndRadius: 'var(--border-radius)',
	},
	'.cm-qn-code-block-last': {
		borderEndStartRadius: 'var(--border-radius)',
		borderEndEndRadius: 'var(--border-radius)',
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

	emits: ['update:modelValue', 'scroll', 'openLink'],

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
		// The scroll position set by scrollToLine, its scroll event is not passed on
		this.expectedScrollTop = null
	},

	mounted() {
		const openLink = (href) => this.$emit('openLink', href)
		this.view = new EditorView({
			parent: this.$refs.container,
			state: EditorState.create({
				doc: this.modelValue,
				extensions: [
					history(),
					drawSelection(),
					highlightActiveLine(),
					highlightSelectionMatches(),
					search({ top: true, createPanel: createSearchPanel }),
					searchPanelTheme,
					Prec.highest(keymap.of([{ key: 'Enter', run: insertTaskAbove }])),
					keymap.of([
						// Like in QOwnNotes Desktop, Ctrl+Space toggles a checkbox or follows the link at the cursor
						{ key: 'Ctrl-Space', run: (view) => toggleTaskCheckbox(view) || openLinkAtCursor(view, openLink), preventDefault: true },
						...defaultKeymap,
						...historyKeymap,
						...searchKeymap,
						indentWithTab,
					]),
					markdownEditorHighlighting(),
					linkClicking(openLink),
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
		this.view.scrollDOM.addEventListener('scroll', this.onScroll, { passive: true })
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
		 * The line at the top of the editor
		 *
		 * @return {number} the line (0-based, with the fraction of the line that is scrolled out of view)
		 */
		scrollLine() {
			const { scrollDOM, state } = this.view
			const { scrollTop, scrollHeight, clientHeight } = scrollDOM
			if (scrollTop > 0 && scrollTop + clientHeight >= scrollHeight - 1) {
				return state.doc.lines
			}
			const height = scrollDOM.getBoundingClientRect().top - this.view.documentTop
			const block = this.view.lineBlockAtHeight(height)
			const line = state.doc.lineAt(block.from).number - 1
			const lines = state.doc.lineAt(block.to).number - line
			return line + Math.min(1, Math.max(0, (height - block.top) / (block.height || 1))) * lines
		},

		/**
		 * Scrolls a line to the top of the editor
		 *
		 * @param {number} line the line (0-based, with a fraction), see scrollLine
		 */
		scrollToLine(line) {
			if (!this.view) {
				return
			}
			const { scrollDOM, state } = this.view
			if (line >= state.doc.lines) {
				scrollDOM.scrollTop = scrollDOM.scrollHeight
			} else {
				const number = Math.max(0, Math.floor(line))
				const block = this.view.lineBlockAt(state.doc.line(number + 1).from)
				const lines = state.doc.lineAt(block.to).number - state.doc.lineAt(block.from).number + 1
				const top = block.top + (line - number) / lines * block.height
				// documentTop is the position of the first line on the screen
				scrollDOM.scrollTop = top + this.view.documentTop - scrollDOM.getBoundingClientRect().top + scrollDOM.scrollTop
			}
			this.expectedScrollTop = scrollDOM.scrollTop
		},

		/**
		 * Moves the cursor to the start of a line and scrolls it to the top, e.g. to jump to a heading
		 *
		 * @param {number} line the line (0-based)
		 */
		jumpToLine(line) {
			// A new editor measures its lines first
			window.requestAnimationFrame(() => {
				if (!this.view) {
					return
				}
				const { from } = this.view.state.doc.line(Math.min(Math.max(1, line + 1), this.view.state.doc.lines))
				this.view.dispatch({ selection: { anchor: from }, effects: EditorView.scrollIntoView(from, { y: 'start' }) })
				this.view.focus()
			})
		},

		/**
		 * Scrolls a line to the top of a new editor, whose lines are not measured yet
		 *
		 * @param {number} line the line (0-based, with a fraction), see scrollLine
		 */
		revealLine(line) {
			// The heights of the lines that come into view are estimated until CodeMirror measured them
			window.requestAnimationFrame(() => {
				this.scrollToLine(line)
				window.requestAnimationFrame(() => this.scrollToLine(line))
			})
		},

		onScroll() {
			const expected = this.expectedScrollTop
			this.expectedScrollTop = null
			if (expected === null || Math.abs(this.view.scrollDOM.scrollTop - expected) > 1) {
				this.$emit('scroll', this.scrollLine())
			}
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

/* Nextcloud styles contenteditable elements like input fields.
   The content fills the editor, so clicks below the text of short notes also place the cursor (Firefox only focuses the scroller there) */
.markdown-editor :deep(.cm-content) {
	width: auto;
	min-height: 100%;
	margin: 0;
	border: none;
	border-radius: 0;
	box-shadow: none;
	background-color: transparent;
}
</style>
