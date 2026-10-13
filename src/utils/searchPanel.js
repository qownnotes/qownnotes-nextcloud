/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import {
	closeSearchPanel,
	findNext,
	findPrevious,
	getSearchQuery,
	replaceAll,
	replaceNext,
	SearchQuery,
	setSearchQuery,
} from '@codemirror/search'
import { EditorView, runScopeHandlers } from '@codemirror/view'
import { t } from '@nextcloud/l10n'

// Matches are only counted up to this number, so large notes stay fast
const MAX_COUNTED_MATCHES = 1000

// Material Design icons, like the rest of the web interface
const ICONS = {
	up: 'M7.41,15.41L12,10.83L16.59,15.41L18,14L12,8L6,14L7.41,15.41Z',
	down: 'M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z',
	expand: 'M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z',
	close: 'M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z',
	magnify: 'M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z',
}

/**
 * @param {string} tag the tag name
 * @param {object} attributes attributes, "on…" ones are event handlers
 * @param {Array<Node|string>} children the children
 * @return {HTMLElement}
 */
function element(tag, attributes = {}, children = []) {
	const node = document.createElement(tag)
	for (const [name, value] of Object.entries(attributes)) {
		if (name.startsWith('on')) {
			node.addEventListener(name.slice(2), value)
		} else if (value !== false && value !== null && value !== undefined) {
			node.setAttribute(name, value === true ? '' : value)
		}
	}
	node.append(...children)
	return node
}

/**
 * @param {string} path the SVG path of the icon
 * @return {SVGElement}
 */
function icon(path) {
	const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	svg.setAttribute('viewBox', '0 0 24 24')
	svg.setAttribute('width', '20')
	svg.setAttribute('height', '20')
	svg.setAttribute('aria-hidden', 'true')
	const iconPath = document.createElementNS('http://www.w3.org/2000/svg', 'path')
	iconPath.setAttribute('d', path)
	iconPath.setAttribute('fill', 'currentColor')
	svg.append(iconPath)
	return svg
}

/**
 * The number of matches and the index of the selected match
 *
 * @param {import('@codemirror/state').EditorState} state the editor state
 * @param {SearchQuery} query the query
 * @return {{total: number, current: number, more: boolean}} current is 0 if no match is selected
 */
export function countMatches(state, query) {
	const result = { total: 0, current: 0, more: false }
	if (!query.valid) {
		return result
	}
	const { from, to } = state.selection.main
	const cursor = query.getCursor(state)
	for (let match = cursor.next(); !match.done; match = cursor.next()) {
		if (result.total === MAX_COUNTED_MATCHES) {
			result.more = true
			break
		}
		result.total++
		if (match.value.from === from && match.value.to === to) {
			result.current = result.total
		}
	}
	return result
}

/**
 * Search and replace panel of the editor, styled like the Nextcloud interface
 */
export class SearchPanel {
	/**
	 * @param {import('@codemirror/view').EditorView} view the editor view
	 */
	constructor(view) {
		this.view = view
		this.query = getSearchQuery(view.state)
		this.replaceOpen = this.query.replace !== ''

		const commit = () => this.commit()
		this.searchField = element('input', {
			type: 'text',
			class: 'qn-search__field',
			value: this.query.search,
			placeholder: t('qownnotes', 'Find'),
			'aria-label': t('qownnotes', 'Find'),
			name: 'search',
			form: '',
			// Focused by CodeMirror when the panel is opened again
			'main-field': 'true',
			autocomplete: 'off',
			spellcheck: 'false',
			oninput: commit,
		})
		this.replaceField = element('input', {
			type: 'text',
			class: 'qn-search__field',
			value: this.query.replace,
			placeholder: t('qownnotes', 'Replace'),
			'aria-label': t('qownnotes', 'Replace'),
			name: 'replace',
			form: '',
			autocomplete: 'off',
			spellcheck: 'false',
			oninput: commit,
		})

		this.caseToggle = this.toggle('Aa', t('qownnotes', 'Match case'), this.query.caseSensitive)
		this.wordToggle = this.toggle('ab', t('qownnotes', 'Match whole word'), this.query.wholeWord, 'qn-search__toggle--word')
		this.regexpToggle = this.toggle('.*', t('qownnotes', 'Use regular expression'), this.query.regexp)
		this.count = element('span', { class: 'qn-search__count', 'aria-live': 'polite' })

		this.expandButton = this.button(icon(ICONS.expand), t('qownnotes', 'Toggle replace'), () => this.setReplaceOpen(!this.replaceOpen), 'qn-search__expand')
		this.replaceRow = element('div', { class: 'qn-search__row qn-search__row--replace' }, [
			element('div', { class: 'qn-search__input' }, [this.replaceField]),
			this.button(t('qownnotes', 'Replace'), t('qownnotes', 'Replace the selected match'), () => replaceNext(this.view), 'qn-search__text-button'),
			this.button(t('qownnotes', 'Replace all'), t('qownnotes', 'Replace all matches'), () => replaceAll(this.view), 'qn-search__text-button'),
		])

		this.dom = element('div', { class: 'qn-search', role: 'search', onkeydown: (event) => this.keydown(event) }, [
			element('div', { class: 'qn-search__row' }, [
				this.expandButton,
				element('div', { class: 'qn-search__input' }, [
					icon(ICONS.magnify),
					this.searchField,
					element('div', { class: 'qn-search__toggles' }, [this.caseToggle, this.wordToggle, this.regexpToggle]),
				]),
				this.count,
				this.button(icon(ICONS.up), t('qownnotes', 'Previous match (Shift+Enter)'), () => findPrevious(this.view)),
				this.button(icon(ICONS.down), t('qownnotes', 'Next match (Enter)'), () => findNext(this.view)),
				this.button(icon(ICONS.close), t('qownnotes', 'Close (Escape)'), () => closeSearchPanel(this.view), 'qn-search__close'),
			]),
			this.replaceRow,
		])

		this.updateReplace()
		this.updateCount()
	}

	/**
	 * @param {Node|string} content the content
	 * @param {string} label the accessible label and tooltip
	 * @param {(event: MouseEvent) => void} onclick the click handler
	 * @param {string} className an additional class
	 * @return {HTMLButtonElement}
	 */
	button(content, label, onclick, className = '') {
		return element('button', {
			type: 'button',
			class: `qn-search__button ${className}`,
			title: label,
			'aria-label': label,
			onclick,
		}, [content])
	}

	/**
	 * @param {string} text the text of the button
	 * @param {string} label the accessible label and tooltip
	 * @param {boolean} pressed whether the option is on
	 * @param {string} className an additional class
	 * @return {HTMLButtonElement}
	 */
	toggle(text, label, pressed, className = '') {
		const button = this.button(text, label, () => {
			button.setAttribute('aria-pressed', String(button.getAttribute('aria-pressed') !== 'true'))
			this.commit()
		}, `qn-search__toggle ${className}`)
		button.setAttribute('aria-pressed', String(pressed))
		return button
	}

	commit() {
		const query = new SearchQuery({
			search: this.searchField.value,
			caseSensitive: this.caseToggle.getAttribute('aria-pressed') === 'true',
			wholeWord: this.wordToggle.getAttribute('aria-pressed') === 'true',
			regexp: this.regexpToggle.getAttribute('aria-pressed') === 'true',
			replace: this.replaceField.value,
		})
		if (!query.eq(this.query)) {
			this.query = query
			this.view.dispatch({ effects: setSearchQuery.of(query) })
		}
	}

	/**
	 * @param {KeyboardEvent} event the event
	 */
	keydown(event) {
		if (runScopeHandlers(this.view, event, 'search-panel')) {
			event.preventDefault()
		} else if (event.key === 'Enter' && event.target === this.searchField) {
			event.preventDefault()
			;(event.shiftKey ? findPrevious : findNext)(this.view)
		} else if (event.key === 'Enter' && event.target === this.replaceField) {
			event.preventDefault()
			;(event.ctrlKey || event.metaKey ? replaceAll : replaceNext)(this.view)
		}
	}

	/**
	 * @param {import('@codemirror/view').ViewUpdate} update the update
	 */
	update(update) {
		for (const transaction of update.transactions) {
			for (const effect of transaction.effects) {
				if (effect.is(setSearchQuery) && !effect.value.eq(this.query)) {
					this.setQuery(effect.value)
				}
			}
		}
		if (update.docChanged || update.selectionSet || update.transactions.some((transaction) => transaction.effects.some((effect) => effect.is(setSearchQuery)))) {
			this.updateCount()
		}
		if (update.startState.readOnly !== update.state.readOnly) {
			this.updateReplace()
		}
	}

	/**
	 * @param {SearchQuery} query the query
	 */
	setQuery(query) {
		this.query = query
		this.searchField.value = query.search
		this.replaceField.value = query.replace
		this.caseToggle.setAttribute('aria-pressed', String(query.caseSensitive))
		this.wordToggle.setAttribute('aria-pressed', String(query.wholeWord))
		this.regexpToggle.setAttribute('aria-pressed', String(query.regexp))
	}

	/**
	 * @param {boolean} open whether the replace field is shown
	 */
	setReplaceOpen(open) {
		this.replaceOpen = open
		this.updateReplace()
		;(open ? this.replaceField : this.searchField).focus()
	}

	updateReplace() {
		const readonly = this.view.state.readOnly
		this.expandButton.hidden = readonly
		this.expandButton.setAttribute('aria-expanded', String(this.replaceOpen))
		this.replaceRow.hidden = readonly || !this.replaceOpen
	}

	updateCount() {
		const invalid = this.query.search !== '' && !this.query.valid
		this.searchField.setAttribute('aria-invalid', String(invalid))
		if (this.query.search === '' || invalid) {
			this.count.textContent = ''
			this.dom.classList.remove('qn-search--no-results')
			return
		}
		const { total, current, more } = countMatches(this.view.state, this.query)
		this.dom.classList.toggle('qn-search--no-results', total === 0)
		const totalText = more ? `${total}+` : String(total)
		if (total === 0) {
			this.count.textContent = t('qownnotes', 'No results')
		} else if (current > 0) {
			this.count.textContent = t('qownnotes', '{current} of {total}', { current, total: totalText })
		} else {
			this.count.textContent = t('qownnotes', '{total} found', { total: totalText })
		}
	}

	mount() {
		this.searchField.select()
	}

	get top() {
		return true
	}
}

/**
 * @param {import('@codemirror/view').EditorView} view the editor view
 * @return {SearchPanel}
 */
export function createSearchPanel(view) {
	return new SearchPanel(view)
}

export const searchPanelTheme = EditorView.theme({
	'.cm-panels.cm-panels-top': {
		borderBottom: '1px solid var(--color-border)',
	},
	'.qn-search': {
		display: 'flex',
		flexDirection: 'column',
		gap: '4px',
		padding: '6px 0',
		fontFamily: 'var(--font-face)',
		fontSize: 'var(--default-font-size)',
	},
	'.qn-search__row': {
		display: 'flex',
		alignItems: 'center',
		gap: '2px',
		minWidth: '0',
	},
	'.qn-search__row[hidden]': {
		display: 'none',
	},
	'.qn-search__row--replace': {
		// Aligned with the search field, beside the expand button
		paddingInlineStart: 'calc(var(--default-clickable-area) + 2px)',
		gap: '4px',
	},
	'.qn-search__input': {
		display: 'flex',
		alignItems: 'center',
		flex: '1 1 auto',
		maxWidth: '480px',
		minWidth: '0',
		height: 'var(--default-clickable-area)',
		paddingInline: '8px 4px',
		gap: '4px',
		boxSizing: 'border-box',
		border: '2px solid var(--color-border-maxcontrast)',
		borderRadius: 'var(--border-radius-element, var(--border-radius-large))',
		backgroundColor: 'var(--color-main-background)',
		color: 'var(--color-text-maxcontrast)',
	},
	'.qn-search__input:focus-within': {
		borderColor: 'var(--color-main-text)',
	},
	'.qn-search--no-results .qn-search__input:has([name=search]), .qn-search__input:has([aria-invalid=true])': {
		borderColor: 'var(--color-border-error, var(--color-error))',
	},
	'.qn-search__input > svg': {
		flexShrink: '0',
	},
	// Nextcloud styles all inputs, the border is drawn by the surrounding element
	'.qn-search__input input.qn-search__field': {
		flex: '1 1 auto',
		minWidth: '40px',
		height: '100%',
		minHeight: '0',
		margin: '0',
		padding: '0 4px',
		border: 'none',
		borderRadius: '0',
		boxShadow: 'none !important',
		outline: 'none',
		background: 'transparent',
		color: 'var(--color-main-text)',
		fontSize: 'inherit',
	},
	'.qn-search__toggles': {
		display: 'flex',
		flexShrink: '0',
		gap: '2px',
	},
	'.qn-search__count': {
		flexShrink: '0',
		minWidth: '72px',
		paddingInline: '8px',
		color: 'var(--color-text-maxcontrast)',
		fontSize: '0.9em',
		whiteSpace: 'nowrap',
		textAlign: 'center',
	},
	'.qn-search--no-results .qn-search__count': {
		color: 'var(--color-text-error, var(--color-error-text))',
	},
	// Nextcloud styles all buttons, these are tertiary buttons like NcButton
	'button.qn-search__button': {
		display: 'inline-flex',
		alignItems: 'center',
		justifyContent: 'center',
		flexShrink: '0',
		width: 'var(--default-clickable-area)',
		minWidth: '0',
		height: 'var(--default-clickable-area)',
		minHeight: '0',
		margin: '0',
		padding: '0',
		border: 'none',
		borderRadius: 'var(--border-radius-element, var(--border-radius-pill))',
		backgroundColor: 'transparent',
		color: 'var(--color-main-text)',
		fontSize: 'inherit',
		fontWeight: 'normal',
		cursor: 'pointer',
	},
	'button.qn-search__button:hover': {
		backgroundColor: 'var(--color-background-hover)',
	},
	'button.qn-search__button:focus-visible': {
		outline: '2px solid var(--color-main-text)',
		outlineOffset: '-2px',
	},
	'button.qn-search__button[hidden]': {
		display: 'none',
	},
	'button.qn-search__expand svg': {
		transition: 'transform var(--animation-quick, 100ms)',
	},
	'button.qn-search__expand[aria-expanded=true] svg': {
		transform: 'rotate(90deg)',
	},
	'button.qn-search__toggle': {
		width: '28px',
		height: '28px',
		borderRadius: 'var(--border-radius)',
		color: 'var(--color-text-maxcontrast)',
		fontFamily: 'var(--font-face-monospace, monospace)',
		fontSize: '0.85em',
	},
	'button.qn-search__toggle--word': {
		textDecoration: 'underline',
		textUnderlineOffset: '3px',
	},
	'button.qn-search__toggle[aria-pressed=true]': {
		backgroundColor: 'var(--color-primary-element-light)',
		color: 'var(--color-primary-element-light-text, var(--color-primary-element))',
		fontWeight: 'bold',
	},
	'button.qn-search__text-button': {
		width: 'auto',
		paddingInline: '12px',
		backgroundColor: 'var(--color-primary-element-light)',
		color: 'var(--color-primary-element-light-text, var(--color-main-text))',
		fontWeight: 'bold',
	},
	'button.qn-search__text-button:hover': {
		backgroundColor: 'var(--color-primary-element-light-hover, var(--color-background-hover))',
	},
	'button.qn-search__close': {
		marginInlineStart: 'auto',
	},
	'.cm-searchMatch': {
		backgroundColor: 'rgba(255, 196, 0, 0.35)',
		outline: '1px solid rgba(255, 196, 0, 0.7)',
		borderRadius: '2px',
	},
	'.cm-searchMatch.cm-searchMatch-selected': {
		backgroundColor: 'rgba(255, 140, 0, 0.55)',
	},
})
