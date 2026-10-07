/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// The web interface uses the same APIs as QOwnNotes Desktop and Android; the session and the CSRF token
// sent by @nextcloud/axios authenticate the requests
export const API_BASE = generateUrl('/apps/qownnotes/api/v1')

// Lets the server keep notes.sqlite and media links up to date on renames, moves and deletions
const RELINK_HEADERS = { 'X-QOwnNotes-Relink-Tags': '1' }

/**
 * All notes (Notes API); notes that didn't change since `pruneBefore` only contain their ID
 *
 * @param {number} pruneBefore Unix timestamp of the last sync, 0 for all notes
 * @return {Promise<{notes: Array<object>, lastModified: number}>}
 */
export async function fetchNotes(pruneBefore = 0) {
	const response = await axios.get(`${API_BASE}/notes`, { params: pruneBefore > 0 ? { pruneBefore } : {} })
	return { notes: response.data, lastModified: Date.parse(response.headers['last-modified'] ?? '') / 1000 || 0 }
}

/**
 * @param {number} id the note ID
 */
export async function fetchNote(id) {
	return (await axios.get(`${API_BASE}/notes/${id}`)).data
}

/**
 * @param {object} note the new note
 * @param {string} note.title the title, which becomes the file name
 * @param {string} note.category the subfolder path
 * @param {string} note.content the text
 */
export async function createNote({ title, category, content }) {
	return (await axios.post(`${API_BASE}/notes`, { title, category, content })).data
}

/**
 * Updates a note; with an etag, the server answers with 412 and the current note if it changed meanwhile
 *
 * @param {number} id the note ID
 * @param {object} changes content, title, category, favorite
 * @param {string|null} etag the known etag of the note
 */
export async function updateNote(id, changes, etag = null) {
	const headers = { ...RELINK_HEADERS }
	if (etag) {
		headers['If-Match'] = `"${etag}"`
	}
	return (await axios.put(`${API_BASE}/notes/${id}`, changes, { headers })).data
}

/**
 * Deletes a note (to the trash bin); its tag links become stale like in QOwnNotes Desktop
 *
 * @param {number} id the note ID
 */
export async function deleteNote(id) {
	await axios.delete(`${API_BASE}/notes/${id}`, { headers: RELINK_HEADERS })
}

/**
 * The settings of the user, see SettingsService
 */
export async function fetchSettings() {
	return (await axios.get(`${API_BASE}/settings`)).data
}

/**
 * @param {object} settings the changed settings, see SettingsService
 */
export async function saveSettings(settings) {
	return (await axios.put(`${API_BASE}/settings`, settings)).data
}

/**
 * Uploads a file into the media (images) or attachments folder of the note folder
 *
 * @param {number} noteId the note that links the file
 * @param {File} file the file
 * @return {Promise<string>} the link to the file, relative to the note
 */
export async function uploadAttachment(noteId, file) {
	const data = new FormData()
	data.append('file', file)
	return (await axios.post(`${API_BASE}/attachment/${noteId}`, data)).data.filename
}

/**
 * The tree of all subfolders, see SubFolderService::getTree
 */
export async function fetchSubFolders() {
	return (await axios.get(`${API_BASE}/subfolders`)).data
}

/**
 * @param {string} path path of the new subfolder
 */
export async function createSubFolder(path) {
	return (await axios.post(`${API_BASE}/subfolders`, { path })).data
}

/**
 * Renames or moves a subfolder; the server updates the tag links of its notes
 *
 * @param {string} path the subfolder
 * @param {string} newPath the new path
 */
export async function moveSubFolder(path, newPath) {
	return (await axios.patch(`${API_BASE}/subfolders`, { path, newPath })).data
}

/**
 * @param {string} path the subfolder
 */
export async function deleteSubFolder(path) {
	return (await axios.delete(`${API_BASE}/subfolders`, { params: { path } })).data
}

/**
 * The tag tree with the ETag of notes.sqlite, see TagService::getTagTree
 */
export async function fetchTags() {
	return (await axios.get(`${API_BASE}/tags`)).data
}

/**
 * All tag links with the IDs of the linked notes
 */
export async function fetchTagLinks() {
	return (await axios.get(`${API_BASE}/tag-links`)).data
}

/**
 * Runs tag operations in one transaction (see TagService::batch)
 *
 * @param {Array<object>} operations the operations
 * @param {string|null} etag the known ETag of notes.sqlite
 */
export async function tagBatch(operations, etag) {
	const headers = etag ? { 'If-Match': `"${etag}"` } : {}
	return (await axios.post(`${API_BASE}/tags/batch`, { operations }, { headers })).data
}

/**
 * Sets the tags of a note; tag paths that don't exist are created
 *
 * @param {number} noteId the note ID
 * @param {string[][]} tagPaths the tag paths, e.g. [["Work", "Project A"]]
 * @param {string|null} etag the known ETag of notes.sqlite
 */
export async function setNoteTags(noteId, tagPaths, etag) {
	const headers = etag ? { 'If-Match': `"${etag}"` } : {}
	return (await axios.put(`${API_BASE}/note/${noteId}/tags`, { tagPaths }, { headers })).data
}

/**
 * The HTTP status of a failed request, or 0
 *
 * @param {unknown} error the error
 * @return {number}
 */
export function errorStatus(error) {
	return error?.response?.status ?? 0
}

/**
 * The error message of the API, if there is one
 *
 * @param {unknown} error the error
 * @return {string}
 */
export function errorMessage(error) {
	return error?.response?.data?.message || error?.message || ''
}

/**
 * File details of a note: file name, path in the user's files, size and whether versions and trash are available
 *
 * @param {number} noteId the note ID
 */
export async function fetchNoteInfo(noteId) {
	return (await axios.get(`${API_BASE}/note/${noteId}/info`)).data
}

/**
 * The previous versions of a note, newest first, with an HTML diff to the current text
 *
 * @param {number} noteId the note ID
 * @return {Promise<Array<{timestamp: number, humanReadableTimestamp: string, diffHtml: string, data: string}>>}
 */
export async function fetchNoteVersions(noteId) {
	return (await axios.get(`${API_BASE}/note/${noteId}/versions`)).data.versions
}

/**
 * Notes deleted from the note folder, newest first
 *
 * @return {Promise<Array<{title: string, fileName: string, subFolderPath: string, originalLocation: string, deleted: number, content: string}>>}
 */
export async function fetchTrash() {
	return (await axios.get(`${API_BASE}/trash`)).data.notes
}

/**
 * Restores a deleted note; its tag links are not stale anymore
 *
 * @param {object} note the trashed note
 * @param {string} note.originalLocation the original path in the user's files
 * @param {number} note.deleted the deletion time
 * @return {Promise<number|null>} the ID of the restored note, if it could be found
 */
export async function restoreTrashedNote({ originalLocation, deleted }) {
	return (await axios.post(`${API_BASE}/trash/restore`, { originalLocation, deleted }, { headers: RELINK_HEADERS })).data.id
}
