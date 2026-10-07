/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// "Open in QOwnNotes" action of the Files app, loaded by FilesScriptsListener. Nextcloud 32 uses the file
// action API of @nextcloud/files 3, Nextcloud 33 and newer the one of @nextcloud/files 4, so both are registered.

import { registerFileAction } from '@nextcloud/files'
import { FileAction as FileActionV3, registerFileAction as registerFileActionV3 } from '@nextcloud/files-v3'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { isNoteFile } from './utils/files.js'

const ACTION_ID = 'qownnotes-open'

// mdiNoteTextOutline
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V9L15,3M19,19H5V5H14V10H19M17,13H7V11H17V13M14,16H7V14H14V16Z" /></svg>'

const config = loadState('qownnotes', 'files', { notesPath: 'Notes', extensions: ['md', 'txt'] })

/**
 * @param {Array<object>} nodes the selected files
 * @return {boolean}
 */
function enabled(nodes) {
	return nodes.length === 1 && isNoteFile({ path: nodes[0].path, type: nodes[0].type }, config)
}

/**
 * @param {object} node the file
 * @return {Promise<null>}
 */
async function exec(node) {
	window.location.href = generateUrl('/apps/qownnotes/note/{id}', { id: node.fileid })
	// null: no success or error message
	return null
}

const action = {
	id: ACTION_ID,
	order: 30,
	iconSvgInline: () => ICON,
	displayName: () => t('qownnotes', 'Open in QOwnNotes'),
}

registerFileAction({
	...action,
	enabled: ({ nodes }) => enabled(nodes),
	exec: ({ nodes }) => exec(nodes[0]),
})

registerFileActionV3(new FileActionV3({
	...action,
	enabled: (nodes) => enabled(nodes),
	exec: (node) => exec(node),
}))
