<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return ['routes' => [
	// Web interface (disabled in API-only mode)
	['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	['name' => 'page#index', 'url' => '/note/{id}', 'verb' => 'GET', 'postfix' => 'note', 'requirements' => ['id' => '\d+']],
	['name' => 'page#index', 'url' => '/folder/{path}', 'verb' => 'GET', 'postfix' => 'folder', 'requirements' => ['path' => '.+']],

	// Admin settings
	['name' => 'admin_settings#save', 'url' => '/admin/settings', 'verb' => 'POST'],

	// Nextcloud Notes API v1 (QOwnNotes Android)
	['name' => 'notes_api#index', 'url' => '/api/v1/notes', 'verb' => 'GET'],
	['name' => 'notes_api#create', 'url' => '/api/v1/notes', 'verb' => 'POST'],
	['name' => 'notes_api#get', 'url' => '/api/v1/notes/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
	['name' => 'notes_api#update', 'url' => '/api/v1/notes/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
	['name' => 'notes_api#destroy', 'url' => '/api/v1/notes/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
	['name' => 'notes_api#get_settings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
	['name' => 'notes_api#set_settings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
	// Attachments were added in Notes API v1.4, whose clients use a "v1.4" path
	['name' => 'notes_api#get_attachment', 'url' => '/api/{apiVersion}/attachment/{noteid}', 'verb' => 'GET', 'requirements' => ['apiVersion' => 'v1(\.4)?', 'noteid' => '\d+']],
	['name' => 'notes_api#upload_file', 'url' => '/api/{apiVersion}/attachment/{noteid}', 'verb' => 'POST', 'requirements' => ['apiVersion' => 'v1(\.4)?', 'noteid' => '\d+']],
	['name' => 'notes_api#delete_attachment', 'url' => '/api/{apiVersion}/attachment/{noteid}', 'verb' => 'DELETE', 'requirements' => ['apiVersion' => 'v1(\.4)?', 'noteid' => '\d+']],
	['name' => 'notes_api#preflighted_cors', 'url' => '/api/{apiVersion}/{path}', 'verb' => 'OPTIONS', 'requirements' => ['apiVersion' => 'v1(\.4)?', 'path' => '.+']],

	// QOwnNotes API 1.0, compatible with the qownnotesapi app (QOwnNotes Desktop and Android)
	['name' => 'q_own_notes_api#get_app_info', 'url' => '/api/v1/note/app_info', 'verb' => 'GET'],
	['name' => 'q_own_notes_api#get_all_versions', 'url' => '/api/v1/note/versions', 'verb' => 'GET'],
	['name' => 'q_own_notes_api#get_trashed_notes', 'url' => '/api/v1/note/trashed', 'verb' => 'GET'],
	['name' => 'q_own_notes_api#restore_trashed_note', 'url' => '/api/v1/note/restore_trashed', 'verb' => 'GET'],
	['name' => 'q_own_notes_api#restore_trashed_note', 'url' => '/api/v1/note/restore_trashed', 'verb' => 'POST', 'postfix' => 'post'],

	// QOwnNotes API 1.1: note subfolders
	['name' => 'sub_folders_api#index', 'url' => '/api/v1/subfolders', 'verb' => 'GET'],
	['name' => 'sub_folders_api#create', 'url' => '/api/v1/subfolders', 'verb' => 'POST'],
	['name' => 'sub_folders_api#move', 'url' => '/api/v1/subfolders', 'verb' => 'PATCH'],
	['name' => 'sub_folders_api#destroy', 'url' => '/api/v1/subfolders', 'verb' => 'DELETE'],

	// QOwnNotes API 1.1: tags of notes.sqlite
	['name' => 'tags_api#index', 'url' => '/api/v1/tags', 'verb' => 'GET'],
	['name' => 'tags_api#create', 'url' => '/api/v1/tags', 'verb' => 'POST'],
	['name' => 'tags_api#batch', 'url' => '/api/v1/tags/batch', 'verb' => 'POST'],
	['name' => 'tags_api#update', 'url' => '/api/v1/tags/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\d+']],
	['name' => 'tags_api#destroy', 'url' => '/api/v1/tags/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
	['name' => 'tags_api#links', 'url' => '/api/v1/tag-links', 'verb' => 'GET'],
	['name' => 'tags_api#get_note_tags', 'url' => '/api/v1/note/{id}/tags', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
	['name' => 'tags_api#set_note_tags', 'url' => '/api/v1/note/{id}/tags', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
]];
