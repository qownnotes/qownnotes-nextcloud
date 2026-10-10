/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { describe, expect, it, vi } from 'vitest'
import { renderMarkdown, toggleTask } from '../../src/utils/markdown.js'
import { attachmentUrl, isRelativeLink, resolveRelativePath } from '../../src/utils/mediaLinks.js'

const options = { apiBase: '/apps/qownnotes/api/v1', noteId: 42 }

vi.mock('@nextcloud/auth', () => ({ getRequestToken: vi.fn(() => 'token+/=') }))

describe('markdown', () => {
	it('serves relative media links with the attachment API', () => {
		const html = renderMarkdown('![x](../media/a%20b.png)\n\n[pdf](../attachments/c.pdf) [web](https://example.com)', options)
		expect(html).toContain('src="/apps/qownnotes/api/v1/attachment/42?path=..%2Fmedia%2Fa%2520b.png&amp;requesttoken=token%2B%2F%3D"')
		expect(html).toContain('href="/apps/qownnotes/api/v1/attachment/42?path=..%2Fattachments%2Fc.pdf&amp;requesttoken=token%2B%2F%3D"')
		expect(html).toContain('href="https://example.com" target="_blank" rel="noopener noreferrer"')
	})

	it('does not add the session token to external images or links', () => {
		const html = renderMarkdown('![remote](https://example.com/image.png) [remote](https://example.com/file.pdf)', options)
		expect(html).toContain('src="https://example.com/image.png"')
		expect(html).toContain('href="https://example.com/file.pdf"')
		expect(html).not.toContain('requesttoken')
	})

	it('uses the current request token when generating attachment URLs', () => {
		vi.mocked(getRequestToken).mockReturnValueOnce('new&token')
		expect(attachmentUrl('/api', 1, 'media/a.png')).toBe('/api/attachment/1?path=media%2Fa.png&requesttoken=new%26token')
	})

	it('removes scripts', () => {
		const html = renderMarkdown('<script>alert(1)</script><img src="x" onerror="alert(1)">\n\n[a](javascript:alert(1)) <a href="javascript:alert(2)">b</a>', options)
		expect(html).not.toContain('<script')
		expect(html).not.toContain('onerror')
		expect(html).not.toContain('href="javascript:')
	})

	it('renders task lists', () => {
		const html = renderMarkdown('- [ ] open\n- [x] done', options)
		expect(html.match(/task-list-item-checkbox/g)).toHaveLength(2)
		expect(html).toContain('checked')
	})

	it('toggles tasks outside of code blocks', () => {
		const content = '- [ ] one\n```\n- [ ] code\n```\n1. [x] two\n* [ ] three'
		expect(toggleTask(content, 0)).toBe('- [x] one\n```\n- [ ] code\n```\n1. [x] two\n* [ ] three')
		expect(toggleTask(content, 1)).toBe('- [ ] one\n```\n- [ ] code\n```\n1. [ ] two\n* [ ] three')
		expect(toggleTask(content, 2)).toBe('- [ ] one\n```\n- [ ] code\n```\n1. [x] two\n* [x] three')
		expect(toggleTask(content, 3)).toBe(content)
	})

	it('resolves relative links', () => {
		expect(isRelativeLink('media/a.png')).toBe(true)
		expect(isRelativeLink('https://example.com')).toBe(false)
		expect(isRelativeLink('/absolute')).toBe(false)
		expect(isRelativeLink('#anchor')).toBe(false)
		expect(resolveRelativePath('Work', '../media/a%20b.png')).toBe('media/a b.png')
		expect(resolveRelativePath('', '../outside.png')).toBeNull()
		expect(attachmentUrl('/api', 1, 'media/a.png?x#y')).toBe('/api/attachment/1?path=media%2Fa.png&requesttoken=token%2B%2F%3D')
	})
})
