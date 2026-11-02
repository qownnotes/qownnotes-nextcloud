/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	bookmarkProblem,
	collectBookmarks,
	editBookmark,
	filterBookmarks,
	isBookmarkNote,
	isOpenableUrl,
	parseBookmarks,
	parseTagsInput,
	removeBookmark,
	serializeBookmark,
} from '../../src/utils/bookmarks.js'

const NOTE = [
	'# Bookmarks',
	'',
	'- [QOwnNotes](https://www.qownnotes.org) #notes #app The notepad',
	'* [Nextcloud](https://nextcloud.com)',
	'  - [Nested](https://example.com/nested) #deep',
	'- [ ] [Task](https://example.com/task)',
	'[Inline](https://example.com/inline)',
	'- [Relative](media/file.png)',
	'- <https://example.com/angle>',
	'',
].join('\n')

describe('bookmarks', () => {
	it('parses list items with links like QOwnNotes Desktop and Android', () => {
		expect(parseBookmarks(NOTE)).toEqual([
			{ url: 'https://www.qownnotes.org', name: 'QOwnNotes', tags: ['notes', 'app'], description: 'The notepad' },
			{ url: 'https://nextcloud.com', name: 'Nextcloud', tags: [], description: '' },
			{ url: 'https://example.com/nested', name: 'Nested', tags: ['deep'], description: '' },
		])
	})

	it('parses Windows line endings', () => {
		expect(parseBookmarks('- [A](https://a.example) #x desc\r\n- [B](https://b.example)\r\n')).toEqual([
			{ url: 'https://a.example', name: 'A', tags: ['x'], description: 'desc' },
			{ url: 'https://b.example', name: 'B', tags: [], description: '' },
		])
	})

	it('merges bookmarks with the same URL', () => {
		const text = '- [A](https://a.example) #b first\n- [Longer](https://a.example) #a second\n'
		expect(parseBookmarks(text)).toEqual([
			{ url: 'https://a.example', name: 'Longer', tags: ['a', 'b'], description: 'first, second' },
		])
	})

	it('collects the bookmarks of several notes with their notes', () => {
		const bookmarks = collectBookmarks([
			{ id: 1, content: '- [A](https://a.example)\n- [B](https://b.example)' },
			{ id: 2, content: '- [A](https://a.example) #x' },
			{ id: 3 },
		])
		expect(bookmarks).toEqual([
			{ url: 'https://a.example', name: 'A', tags: ['x'], description: '', noteIds: [1, 2] },
			{ url: 'https://b.example', name: 'B', tags: [], description: '', noteIds: [1] },
		])
	})

	it('finds bookmark notes by tag or name', () => {
		const tagIds = new Set([7])
		expect(isBookmarkNote({ title: 'Bookmarks' }, undefined, tagIds)).toBe(true)
		expect(isBookmarkNote({ title: 'bookmarks' }, undefined, tagIds)).toBe(false)
		expect(isBookmarkNote({ title: 'Links' }, new Set([3, 7]), tagIds)).toBe(true)
		expect(isBookmarkNote({ title: 'Links' }, new Set([3]), tagIds)).toBe(false)
	})

	it('filters by words and tags and sorts by name', () => {
		const bookmarks = parseBookmarks(NOTE)
		expect(filterBookmarks(bookmarks, '').map((bookmark) => bookmark.name)).toEqual(['Nested', 'Nextcloud', 'QOwnNotes'])
		expect(filterBookmarks(bookmarks, 'NOTEPAD qown').map((bookmark) => bookmark.name)).toEqual(['QOwnNotes'])
		expect(filterBookmarks(bookmarks, 'example').map((bookmark) => bookmark.name)).toEqual(['Nested'])
		expect(filterBookmarks(bookmarks, '#deep').map((bookmark) => bookmark.name)).toEqual(['Nested'])
		expect(filterBookmarks(bookmarks, '', ['notes', 'app']).map((bookmark) => bookmark.name)).toEqual(['QOwnNotes'])
		expect(filterBookmarks(bookmarks, '', ['notes', 'deep'])).toEqual([])
	})

	it('parses the tags field', () => {
		expect(parseTagsInput(' #work  news #work ##x ')).toEqual(['work', 'news', 'x'])
		expect(parseTagsInput('')).toEqual([])
	})

	it('serializes bookmarks', () => {
		expect(serializeBookmark({ url: 'https://a.example', name: 'A', tags: ['x', 'y'], description: ' text ' }))
			.toBe('- [A](https://a.example) #x #y text')
		expect(serializeBookmark({ url: 'https://a.example', name: 'A', tags: [], description: '' })).toBe('- [A](https://a.example)')
	})

	it('validates edited bookmarks', () => {
		const valid = { url: 'https://a.example', name: 'A', tags: ['x'], description: 'text' }
		expect(bookmarkProblem(valid)).toBeNull()
		expect(bookmarkProblem({ ...valid, name: '' })).toBe('name')
		expect(bookmarkProblem({ ...valid, name: 'a [b]' })).toBe('name')
		expect(bookmarkProblem({ ...valid, name: 'a\nb' })).toBe('name')
		expect(bookmarkProblem({ ...valid, url: 'example.com' })).toBe('url')
		expect(bookmarkProblem({ ...valid, url: 'https://a.example/a b' })).toBe('url')
		expect(bookmarkProblem({ ...valid, url: 'https://en.wikipedia.org/wiki/A_(b)' })).toBe('name')
		expect(bookmarkProblem({ ...valid, tags: ['a#b'] })).toBe('tags')
		expect(bookmarkProblem({ ...valid, description: 'see #other' })).toBe('description')
		expect(bookmarkProblem({ ...valid, description: 'issue # 5' })).toBeNull()
	})

	it('edits all list items of a bookmark and keeps list characters and indentation', () => {
		const text = '# B\r\n\r\n* [Old](https://a.example) #x old\r\n  - [Other](https://b.example)\r\n  - [Again](https://a.example)'
		const replacement = { url: 'https://new.example', name: 'New', tags: ['y'], description: 'new' }
		expect(editBookmark(text, 'https://a.example', replacement)).toBe('# B\r\n\r\n* [New](https://new.example) #y new\r\n  - [Other](https://b.example)\r\n  - [New](https://new.example) #y new')
		expect(editBookmark(text, 'https://missing.example', replacement)).toBe(text)
	})

	it('removes bookmarks with their lines', () => {
		const text = '# B\n\n- [A](https://a.example) #x\n  * [B](https://b.example)\nSee - [A](https://a.example)\n- [A](https://a.example)'
		expect(removeBookmark(text, 'https://a.example')).toBe('# B\n\n  * [B](https://b.example)\nSee \n')
		expect(removeBookmark('a\r\n- [A](https://a.example)\r\nb', 'https://a.example')).toBe('a\r\nb')
		expect(removeBookmark(text, 'https://missing.example')).toBe(text)
	})

	it('only opens web URLs', () => {
		expect(isOpenableUrl('https://a.example')).toBe(true)
		expect(isOpenableUrl('HTTP://a.example')).toBe(true)
		expect(isOpenableUrl('javascript://alert(1)')).toBe(false)
		expect(isOpenableUrl('ftp://a.example')).toBe(false)
		expect(isOpenableUrl('https:///path')).toBe(false)
	})
})
