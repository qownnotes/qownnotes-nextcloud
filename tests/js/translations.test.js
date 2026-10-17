// @vitest-environment node

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

// Bypass Vite's browser polyfills for Node built-ins in this catalog-only test.
const { readFileSync } = globalThis.process.getBuiltinModule('node:fs')
const { runInNewContext } = globalThis.process.getBuiltinModule('node:vm')

const counts = [0, 1, 2, 5, 11, 21, 22, 1000000, 1.5]
const languages = {
	de: [1, 0, 1, 1, 1, 1, 1, 1, 1],
	zh_CN: [0, 0, 0, 0, 0, 0, 0, 0, 0],
	ru: [2, 0, 1, 2, 2, 0, 1, 2, 3],
	it: [2, 0, 2, 2, 2, 2, 2, 1, 2],
	pt_BR: [0, 0, 2, 2, 2, 2, 2, 1, 2],
	pl: [2, 0, 1, 2, 2, 2, 1, 2, 3],
	fr: [0, 0, 2, 2, 2, 2, 2, 1, 2],
	es: [2, 0, 2, 2, 2, 2, 2, 1, 2],
}

describe.each(Object.entries(languages))('%s translations', (language, indices) => {
	const catalog = JSON.parse(readFileSync(new URL(`../../l10n/${language}.json`, import.meta.url), 'utf8'))

	it('registers the same translations and plural rule for the browser and PHP', () => {
		const register = vi.fn()
		const script = readFileSync(new URL(`../../l10n/${language}.js`, import.meta.url), 'utf8')
		runInNewContext(script, { OC: { L10N: { register } } })
		expect(register).toHaveBeenCalledExactlyOnceWith('qownnotes', catalog.translations, catalog.pluralForm)
		expect(Object.keys(catalog.translations)).toHaveLength(161)
	})

	it('selects the correct plural forms, including zero and large counts', () => {
		const pluralEntries = Object.entries(catalog.translations).filter(([key]) => key.includes('_::_'))
		expect(pluralEntries).toHaveLength(2)
		for (const [i, n] of counts.entries()) {
			const index = runInNewContext(`let nplurals, plural; ${catalog.pluralForm}; Number(plural)`, { n })
			expect(index, `plural index for ${n}`).toBe(indices[i])
			for (const [, forms] of pluralEntries) {
				expect(forms[index]).toContain('{count}')
			}
		}
	})
})
