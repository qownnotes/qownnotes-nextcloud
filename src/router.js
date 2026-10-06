/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'

const empty = { render: () => null }

// The page may be opened with or without "index.php" in the URL, independent of the URL generation settings
const base = window.location.pathname.match(/^.*?\/apps\/qownnotes(?=\/|$)/u)?.[0] ?? generateUrl('/apps/qownnotes')

// The routes only hold the state of the URL, App.vue renders everything; the server side routes that serve
// the page for these URLs are in appinfo/routes.php
export default createRouter({
	history: createWebHistory(base),
	routes: [
		{ path: '/', name: 'all', component: empty },
		// The selected subfolder ('' for the root of the note folder)
		{ path: '/folder/:path*', name: 'folder', component: empty },
		// The selected note; the optional query parameter "folder" keeps the selected subfolder
		{ path: '/note/:id(\\d+)', name: 'note', component: empty },
		{ path: '/:pathMatch(.*)*', redirect: '/' },
	],
})

/**
 * The selected subfolder of a route: null for all notes, '' for the root of the note folder
 *
 * @param {import('vue-router').RouteLocationNormalized} route the route
 * @return {string|null}
 */
export function routeFolder(route) {
	if (route.name === 'folder') {
		const path = route.params.path
		return Array.isArray(path) ? path.join('/') : (path ?? '')
	}
	if (route.name === 'note' && typeof route.query.folder === 'string') {
		return route.query.folder
	}
	return null
}

/**
 * The selected note ID of a route, or null
 *
 * @param {import('vue-router').RouteLocationNormalized} route the route
 * @return {number|null}
 */
export function routeNoteId(route) {
	return route.name === 'note' ? Number(route.params.id) : null
}

/**
 * The route of a note, keeping the selected subfolder
 *
 * @param {number} id the note ID
 * @param {string|null} folder the selected subfolder
 */
export function noteRoute(id, folder) {
	return { name: 'note', params: { id: String(id) }, query: folder === null ? {} : { folder } }
}

/**
 * The route of a subfolder, or of all notes
 *
 * @param {string|null} folder the subfolder
 */
export function folderRoute(folder) {
	return folder === null ? { name: 'all' } : { name: 'folder', params: { path: folder === '' ? [] : folder.split('/') } }
}
