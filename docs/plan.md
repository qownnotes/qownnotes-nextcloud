# QOwnNotes for Nextcloud: Planning Document

Status: Draft
App ID: `qownnotes` (namespace `OCA\QOwnNotes`)
Repository: `qownnotes-nextcloud`

## 1. Summary

Build a new Nextcloud app, **QOwnNotes**, that

1. lets users view and edit their QOwnNotes note folder from the Nextcloud web interface, comparable to [Nextcloud Notes](https://github.com/nextcloud/notes) but behaving like [QOwnNotes Desktop](https://github.com/pbek/QOwnNotes),
2. has **real note subfolder support** like QOwnNotes Desktop (nested subfolders, folder tree, folder operations, ignore rules),
3. has **real tag support backed by the note folder's `notes.sqlite`**: the same tags, hierarchy, colors and note links that QOwnNotes Desktop and Android use, readable and writable from the web and through an API,
4. serves a **Nextcloud Notes API v1 compatible REST API** for [QOwnNotes Android](https://github.com/qownnotes/qownnotes-android),
5. incorporates the **QOwnNotesAPI** endpoints (note versions, trash, app info) from [pbek/qownnotesapi](https://github.com/pbek/qownnotesapi) for QOwnNotes Desktop and Android,
6. can run in **API-only mode**, where an administrator turns off the web UI and only the APIs stay available.

The app is written from scratch. Nextcloud Notes, QOwnNotesAPI, QOwnNotes Desktop and QOwnNotes Android are used as **behavioral specifications** (API docs, data formats, request/response shapes, existing client code), not as code to copy.

## 2. Goals and Non-Goals

### Goals

- Notes stay plain Markdown files in the user's Nextcloud files. Files are the source of truth for notes and subfolders, and `notes.sqlite` is the source of truth for tags. The server keeps no second copy of either.
- Full compatibility with the request/response formats and on-disk formats that QOwnNotes Desktop and Android already rely on.
- One server app for all QOwnNotes clients (desktop, Android, web) instead of "Notes app + QOwnNotesAPI app".
- Coexistence with the Nextcloud Notes app and the legacy QOwnNotesAPI app on the same server.
- Optional web UI (API-only mode).
- Reproducible development and testing through devenv and NixOS VM tests across all supported Nextcloud versions.

### Non-Goals

- Replacing the Nextcloud Notes app for Nextcloud Notes clients. The Notes API is served for QOwnNotes clients and is not advertised under the `notes` capability (see section 4.4).
- Notes API v0.2 (deprecated, unused by QOwnNotes clients).
- Server-side handling of the desktop's _local_ settings (`QOwnNotes.sqlite`, note folder list, scripts, bookmarks, calendar). Only the per-note-folder `notes.sqlite` is in scope.
- Changing the `notes.sqlite` schema. The server only reads and writes the existing desktop schema (see 4.9).
- ownCloud support. qownnotesapi still supports ownCloud, and it stays the ownCloud option.

## 3. Findings From the Existing Clients

### 3.1 Server endpoints

Nextcloud places all app routes under `/index.php/apps/<app-id>/`, so the app ID decides the URLs clients must call. Today the clients hardcode these paths:

| Client            | Endpoint base                                    | Used for                                                                                                                                                         | Requirements                                                                                                             |
| ----------------- | ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| QOwnNotes Desktop | `/index.php/apps/qownnotesapi/api/v1/`           | `note/app_info`, `note/versions`, `note/trashed`, `note/restore_trashed`                                                                                         | `app_version >= 0.4.2` (`QOWNNOTESAPI_MIN_VERSION`), `versioning: true`                                                  |
| QOwnNotes Desktop | `/remote.php/dav/files/<user>/`, OCS             | Notes and `notes.sqlite` themselves (via Nextcloud desktop sync), sharing (`files_sharing` OCS), capabilities, Deck, calendar                                    | Unaffected by this app                                                                                                   |
| QOwnNotes Android | `/index.php/apps/notes/api/v1/`                  | `settings` (GET/PUT), `notes` (GET with `pruneBefore`, `chunkSize`, `chunkCursor`, `If-None-Match`), `notes/{id}` (GET/PUT with `If-Match`/DELETE), `POST notes` | `notes` capability with `api_version` 1.x where x >= 2. `ETag`, `X-Notes-Chunk-Cursor`, `X-Notes-Chunk-Pending` headers. |
| QOwnNotes Android | `/index.php/apps/notes/api/v1.4/attachment/{id}` | Attachments / images                                                                                                                                             | Notes API 1.4                                                                                                            |
| QOwnNotes Android | `/index.php/apps/qownnotesapi/api/v1/`           | Same four `note/*` endpoints as desktop                                                                                                                          | `app_version >= 0.4.4`, `notes_path_exists`, `versions_app`, `trash_app`                                                 |
| QOwnNotes Android | WebDAV `<notesPath>/notes.sqlite`                | Tags: download, modify a private copy, upload with `If-Match`                                                                                                    | Desktop schema version 15–16, rollback-journal (non-WAL) file                                                            |

Consequence of the new app ID: **both clients need an update** to discover and use `/index.php/apps/qownnotes/...`. Until users have updated clients, the Nextcloud Notes app and the qownnotesapi app keep working side by side with this app. Section 10 has the migration plan.

### 3.2 `notes.sqlite` (per note folder, written by Desktop and Android)

Schema as of desktop database version 16 (`DatabaseService::setupNoteFolderTables` / `repairNoteFolderSchema`):

```sql
CREATE TABLE appData (name VARCHAR(255) PRIMARY KEY, value VARCHAR(255));
-- appData('database_version') = '16'

CREATE TABLE tag (
  id INTEGER PRIMARY KEY,
  name VARCHAR(255) COLLATE NOCASE,
  priority INTEGER DEFAULT 0,
  created DATETIME DEFAULT current_timestamp,
  parent_id INTEGER DEFAULT 0,          -- 0 = top level; hierarchical tags
  color VARCHAR(20),
  dark_color VARCHAR(20),
  updated DATETIME DEFAULT current_timestamp  -- "recently used" ordering
);
CREATE INDEX idxTagParent ON tag (parent_id);
CREATE UNIQUE INDEX idxUniqueTag ON tag (name, parent_id);

CREATE TABLE noteTagLink (
  id INTEGER PRIMARY KEY,
  tag_id INTEGER,
  note_file_name VARCHAR(255) DEFAULT '',   -- file name incl. suffix, e.g. "Meeting.md"
  note_sub_folder_path TEXT DEFAULT '',     -- "/"-separated, relative to note folder, '' = root
  created DATETIME DEFAULT current_timestamp,
  stale_date DATETIME DEFAULT NULL          -- set when the note is missing; purged after 10 days
);
CREATE UNIQUE INDEX idxUniqueTagNoteLink ON noteTagLink (tag_id, note_file_name, note_sub_folder_path);

CREATE TABLE trashItem (...);  -- desktop-local trash, not touched by the server
```

Desktop behaviors the server must reproduce:

- **Identity**: a note is identified only by `(note_file_name, note_sub_folder_path)`. There are no IDs shared with files.
- **Rename note**: `UPDATE noteTagLink SET note_file_name = new WHERE note_file_name = old AND note_sub_folder_path = path`.
- **Rename/move subfolder**: prefix-only replacement (`path = old OR path LIKE old || '/%'`) so `work` does not affect `workplace`.
- **Missing notes**: links are marked stale (`stale_date`), un-staled if the note reappears, and deleted once stale for more than 10 days (`Tag::removeBrokenLinks`).
- **Tag lookup** is case-insensitive per parent (`COLLATE NOCASE`). Linking a tag touches `updated` of the tag and all its ancestors.
- Android's `NoteFolderTagDatabase.kt` already writes this file remotely and sets the rules the server follows: only open a private copy, verify the SQLite header (bytes 18/19 == 1, no WAL), run `PRAGMA quick_check`, check required columns, only write for known schema versions (15–16), keep `journal_mode=DELETE`, never touch other tables, and upload only when something changed.

### 3.3 Note subfolders (Desktop)

- Arbitrarily nested subfolders below the note folder root, with notes allowed at every level including root.
- Always ignored folder names: `.`, `..`, `media`, `attachments`, `trash`. In addition, folders matching the user-configurable regex list `ignoreNoteSubFolders` (`;`-separated, default `^\.`) are ignored.
- `media/` and `attachments/` live at the note folder root. Notes in subfolders link them relatively (for example `../media/image.png`).
- Display options: show the notes of the selected folder only or of the folder including all its subfolders. Tags can be filtered in combination with the selected subfolder.

## 4. Architecture

### 4.1 Overview

```
                         ┌──────────────────────────── qownnotes app ────────────────────────────┐
 Web UI (Vue 3) ───────► │ PageController / WebApiController       (disabled in API-only mode)   │
                         │                                                                        │
 QOwnNotes Android ────► │ NotesApiController       /api/v1/notes, /settings, /attachment        │
                         │                                                                        │
 QOwnNotes Desktop ────► │ QOwnNotesApiController   /api/v1/note/{app_info,versions,trashed,…}   │
 QOwnNotes Android ────► │                          /api/v1/subfolders, /api/v1/tags, …          │
                         │ ─────────────────────── shared service layer ───────────────────────  │
                         │ NoteService · SubFolderService · TagService · TagDatabase (notes.sqlite)│
                         │ MetaService · SettingsService · VersionService · TrashService          │
                         │ AttachmentService · FavoriteService                                    │
                         └──────────────────────────────────┬─────────────────────────────────────┘
                                                            │ OCP public APIs only
                         IRootFolder · ILockingProvider · IVersionManager · ITrashManager · ITagManager
                                                            │
                               User files: <notesPath>/**/*.md, <notesPath>/notes.sqlite
```

Controllers stay thin and only translate HTTP to service calls and back. All surfaces share one service layer, so behavior (title sanitizing, ETags, ignore rules, tag relinking, permissions) is identical everywhere.

### 4.2 Backend layout

```
appinfo/info.xml, routes.php
lib/AppInfo/Application.php              # IBootstrap: conditional registration (see 4.7)
lib/Capabilities.php
lib/Controller/PageController.php        # web UI entry, 404 when UI disabled
lib/Controller/WebApiController.php      # internal endpoints for the Vue UI (not needed so far, see 4.3)
lib/Controller/NotesApiController.php    # Notes API v1.0–1.4 compatible
lib/Controller/QOwnNotesApiController.php # legacy endpoints + subfolders + tags
lib/Controller/ApiResponseTrait.php      # ETag/Last-Modified/X-Notes-* headers, exception → status mapping
lib/Service/NoteService.php              # note CRUD, title ↔ file name, suffixes
lib/Service/SubFolderService.php         # tree, ignore rules, create/rename/move/delete
lib/Service/TagService.php               # tag domain logic (tree, links, relinking, stale handling)
lib/Service/TagDatabase.php              # safe read-modify-write of notes.sqlite (see 4.9)
lib/Service/{Meta,Settings,Version,Trash,Attachment,Favorite}Service.php
lib/Db/Meta.php, MetaMapper.php          # per-note metadata cache
lib/Migration/*
lib/Settings/Admin.php, AdminSection.php # admin settings incl. API-only mode
lib/Listener/*                           # file events → meta invalidation
src/                                     # Vue 3 frontend
tests/unit, tests/integration, tests/vm
```

Rules:

- Only **public `OCP\` APIs**. qownnotesapi uses private classes (`OC\Files\View`, `OC_User`, `OCA\Files_Versions\Storage`, `OCA\Files_Trashbin\Trashbin`), and these keep breaking across Nextcloud releases (for example issue #50 and the Nextcloud 35 `formatFileInfos` removal). Replace them with `OCP\Files\IRootFolder`, `OCA\Files_Versions\Versions\IVersionManager` and `OCA\Files_Trashbin\Trash\ITrashManager`, with graceful degradation when those apps are disabled.
- PHP 8.2+, strict types, PHP attributes for routing metadata (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[CORS]`, `#[ApiRoute]`/`#[FrontpageRoute]` where available).
- SPDX headers in every file (`AGPL-3.0-or-later`), REUSE compliant.

### 4.3 Routes

All APIs live under `/index.php/apps/qownnotes/`.

| Surface       | Route                                                                  | Notes                                                                 |
| ------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------- |
| Web UI        | `GET /`, `GET /note/{id}`, `GET /folder/{path}`                        | Disabled in API-only mode                                             |
| Web internal  | none: the web UI uses the APIs below with the session and CSRF token   | No duplicated endpoints. The page itself is disabled in API-only mode |
| Notes API     | `GET/POST /api/v1/notes`                                               | Same semantics as Nextcloud Notes API v1                              |
|               | `GET/PUT/DELETE /api/v1/notes/{id}`                                    | `If-Match` → 412 on conflict                                          |
|               | `GET/PUT /api/v1/settings`                                             | `notesPath`, `fileSuffix` (custom suffix as of 1.3)                   |
|               | `GET/POST/DELETE /api/v1/attachment/{noteid}`                          | Also routed as `/api/v1.4/attachment/{noteid}` for parity with Notes  |
|               | `OPTIONS /api/v1/{path}`                                               | CORS preflight                                                        |
| QOwnNotes API | `GET /api/v1/note/app_info`                                            | Byte-compatible with qownnotesapi                                     |
|               | `GET /api/v1/note/versions`                                            | Byte-compatible with qownnotesapi                                     |
|               | `GET /api/v1/note/trashed`                                             | Byte-compatible with qownnotesapi                                     |
|               | `GET /api/v1/note/restore_trashed`                                     | Kept as GET for compatibility. Add `POST` variant for new clients     |
|               | `GET/POST /api/v1/subfolders`, `PATCH/DELETE /api/v1/subfolders?path=` | Subfolder tree and operations (4.8)                                   |
|               | `GET/POST /api/v1/tags`, `PATCH/DELETE /api/v1/tags/{id}`              | Tag tree and management (4.9)                                         |
|               | `GET/PUT /api/v1/note/{id}/tags`                                       | Tags of one note (`id` = Notes API note ID)                           |
|               | `GET /api/v1/tag-links`                                                | All links for a full client sync. Supports `If-None-Match`            |
|               | `GET /api/v1/note/{id}/info`, `GET /api/v1/note/{id}/versions`         | File details and versions by note ID (web UI sidebar)                 |
|               | `GET /api/v1/trash`, `POST /api/v1/trash/restore`                      | Deleted notes of the note folder. Restore un-stales tag links         |

`note/*`, `subfolders`, `tags`, `tag-links` (QOwnNotes) and `notes/*` (Notes) do not collide, so everything can share the `/api/v1/` prefix and clients only need one base URL. Do **not** add a catch-all `/api/{path}` route (Notes has one), because it would shadow these routes depending on order.

Every API response carries `X-Notes-API-Versions: 1.4` and `X-QOwnNotes-API-Versions: 1.1`. QOwnNotes API 1.0 is the legacy endpoint set. 1.1 adds subfolders and tags.

### 4.4 Capabilities

Published under the `qownnotes` key only:

```json
{
  "qownnotes": {
    "version": "26.10.0",
    "notes_api_version": ["1.4"],
    "qownnotes_api_version": ["1.1"],
    "api_base": "/index.php/apps/qownnotes/api/v1/",
    "ui_enabled": true,
    "notes_path": "Notes",
    "versions_app": true,
    "trash_app": true,
    "tags": { "available": true, "writable_schema_versions": [15, 16] }
  }
}
```

`tags.available` is false when `pdo_sqlite` is missing on the server. Whether the user's own `notes.sqlite` is writable is reported by `GET /api/v1/tags` (`writable`, `schemaVersion`), because reading it in every capabilities request would be too expensive.

The app must **not** publish a `notes` capability. Nextcloud merges capabilities from all apps, so publishing `notes` would overwrite or merge with the real Notes app's entry. Nextcloud Notes Android would then call `/apps/notes/...` with wrong version assumptions, and QOwnNotes Android would wrongly detect the Notes app. The VM test asserts this.

### 4.5 Notes API (QOwnNotes Android)

Implement Notes API **v1.0–1.4** per the [Notes API v1 docs](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md):

- Note attributes: `id` (file ID), `etag`, `readonly`, `content`, `title`, `category`, `favorite`, `modified`. `category` is the subfolder path (`/`-separated), using the same rules as 4.8.
- The read-only QOwnNotes extension `internalPath` is the note's path inside the user's files root, with a leading `/` and the actual suffix. Android requires it to call the legacy versions API.
- `GET /notes`: `category`, `exclude`, `pruneBefore`, `chunkSize`/`chunkCursor`. Response headers `ETag`, `Last-Modified`, `X-Notes-Chunk-Cursor`, `X-Notes-Chunk-Pending`. Pruned notes contain only `id`. The chunk cursor is an opaque, versioned string (for example base64 of `{lastUpdate, lastNoteId, version}`), and an invalid cursor returns 400.
- `If-None-Match` → 304, `If-Match` mismatch → 412 with the current note in the body.
- Status codes: 400 invalid input, 403 read-only, 404 missing, 412 conflict, 423 locked, 507 insufficient storage.
- `PUT /settings` validates and normalizes `notesPath` (strip `..`, `.`, leading/trailing slashes) and creates the folder.
- Attachments (1.4): `GET ?path=` resolves the path relative to the note's folder and refuses paths outside the notes folder. `POST` uploads into the root `media/` (images) or `attachments/` folder and returns a QOwnNotes-style relative link.
- Notes in ignored subfolders (4.8) are never listed and cannot be created there (400).
- **Tags and the Notes API**: the Notes API itself does not touch `notes.sqlite`, because Android currently relinks tags itself after renames and moves and uploads the file with `If-Match`. An implicit server-side relink would make Android's upload fail with 412 every time. Clients that switch to the tags API (4.9) can opt in to server-side relinking by sending `X-QOwnNotes-Relink-Tags: 1` on `PUT /notes/{id}`. The header also enables the rewriting of relative media and attachment links when a note changes its subfolder depth (4.8), and marks the tag links of deleted notes stale. The web UI always sends it.

Conformance is tested against the documented behavior **and** against the actual requests of `NextcloudBackend.kt` (section 3). Nextcloud Notes' own `tests/api` suite is a useful reference for writing equivalent tests.

### 4.6 QOwnNotes API: legacy endpoints (Desktop and Android)

Re-implement the four qownnotesapi endpoints with identical parameter names and JSON field names:

| Endpoint               | Params                                         | Response fields                                                                                         |
| ---------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `note/app_info`        | `notes_path`                                   | `user`, `versions_app`, `trash_app`, `versioning`, `app_version`, `server_version`, `notes_path_exists` |
| `note/versions`        | `file_name` (path relative to user root)       | `file_name`, `versions[] {timestamp, humanReadableTimestamp, diffHtml, data}`, `error_messages[]`       |
| `note/trashed`         | `dir`, `extensions[]`, `sort`, `sortdirection` | `directory`, `notes[] {noteName, fileName, timestamp, dateString, data}`                                |
| `note/restore_trashed` | `file_name`, `timestamp`                       | `result`, `path`, `filename`                                                                            |

Details:

- `app_version` is the app's version. With CalVer (`26.x.y`) it satisfies the clients' `>= 0.4.2` / `>= 0.4.4` checks.
- `diffHtml`: QOwnNotes Desktop renders it in its versions dialog. Produce the same `<ins>`/`<del>` HTML structure as FineDiff. Either vendor FineDiff (MIT, as qownnotesapi does in `3rdparty/`) or use a maintained Composer diff library with an HTML renderer that matches the structure. Snapshot tests should lock the format down.
- Version and trash access go through `IVersionManager` / `ITrashManager` and are limited to files owned by or shared with the requesting user. Large trash listings read content lazily, and a later API version may add a `?content=0` option.
- Restoring a trashed note un-stales its tag links (4.9).
- Errors keep the current loose semantics (empty lists, `error_messages`) for compatibility. The new 1.1 endpoints use proper HTTP status codes.

### 4.7 API-only mode (UI on/off setting)

Some admins only want the sync APIs and no extra navigation entry, for example servers that already use the Nextcloud Notes web UI, or headless setups.

**Setting**

- App config key `qownnotes/ui_enabled` (`yes`/`no`, default `yes`), stored via `IAppConfig` (non-lazy so it can be read cheaply during boot).
- UI: admin settings section "QOwnNotes" (`lib/Settings/Admin.php`) with a "Enable web interface" toggle and explanatory text ("When disabled, only the APIs for QOwnNotes Desktop and Android are available").
- CLI: `occ config:app:set qownnotes ui_enabled --value=no`.
- Optional, later: a per-user preference "Hide QOwnNotes from navigation", for users who only sync from devices. It only applies while the admin setting allows the UI.

**Effects when `ui_enabled = no`**

| Component                                                                              | Behavior                                                                                                                                                                                   |
| -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Navigation entry                                                                       | Not registered. The entry must be added programmatically via `INavigationManager::add()` in `Application::boot()` and **not** declared statically in `info.xml`, so it can be conditional. |
| `PageController` / web internal routes                                                 | Return 404 (`NotFoundResponse`), or redirect to Files for `GET /`                                                                                                                          |
| Frontend scripts/styles                                                                | Not loaded (`Util::addScript` only in page responses and enabled integrations)                                                                                                             |
| Dashboard widget, unified search, reference provider, Files "Open in QOwnNotes" action | Not registered                                                                                                                                                                             |
| Notes API, QOwnNotes API (incl. subfolders and tags), capabilities                     | **Unaffected**. Capabilities report `ui_enabled: false` so clients can hide "Open in browser" links                                                                                        |
| Background jobs / file listeners                                                       | Unaffected (needed by the APIs)                                                                                                                                                            |

Routes in `routes.php` are static, so the page and web controllers check the setting through a small middleware (`UiEnabledMiddleware`) or a controller attribute `#[RequiresUi]` instead of conditional route registration. This keeps the check in one place.

**Tests**: PHPUnit for middleware, navigation and registration. The VM test toggles the setting via `occ` and asserts navigation, page 404 and working APIs in both modes.

### 4.8 Note subfolders

Subfolders are real folders below `<notesPath>`. There is no separate database, and the files are the source of truth.

**Model** (`SubFolderService`)

- Tree of all non-ignored folders below the note folder, including **empty** folders (the Notes API cannot represent those, which is why `GET /api/v1/subfolders` exists).
- Each node has `path` (`/`-separated, relative to the note folder, `''` = root, which is exactly the `note_sub_folder_path` used in `notes.sqlite`), `name`, `noteCount`, `noteCountRecursive`, `mtime`, `readonly` and `children`.
- Ignore rules identical to Desktop: always `.`, `..`, `media`, `attachments`, `trash`, plus the per-user regex list `ignoreNoteSubFolders` (`;`-separated, default `^\.`), editable in the web settings and via `PUT /api/v1/settings` (extra key, ignored by Notes clients). Notes inside ignored folders do not exist for any API.
- Optional per-user setting `subfoldersEnabled` (default true). When false, only root notes are shown, like Desktop with subfolders disabled.

**Operations** (web UI and `subfolders` API, all `If-Match`-protected where applicable)

| Operation                   | Files                                   | `notes.sqlite` (if present and writable)                                                     |
| --------------------------- | --------------------------------------- | -------------------------------------------------------------------------------------------- |
| Create folder               | `mkdir` (name validated, ignore rules)  | –                                                                                            |
| Rename / move folder        | `move` (target inside note folder only) | Prefix-only relink of `note_sub_folder_path`, the same SQL semantics as Desktop              |
| Delete folder               | Move to Nextcloud trash                 | Mark contained links stale. They return to normal when the folder is restored within 10 days |
| Move note to another folder | `move`                                  | Relink `(file, oldPath)` → `(file, newPath)`, merging duplicates like Android's `relink`     |
| Rename note (title change)  | `move`                                  | Relink `note_file_name`                                                                      |

Relative media and attachment links inside moved notes are rewritten (`../media/x.png` ↔ `../../media/x.png`) when a note changes depth, as Desktop does when moving notes between subfolders. This is a per-user option (default on) because it changes the note content.

**Web UI**: a folder tree in the navigation with drag and drop of notes and folders, context menu (new note here, new subfolder, rename, delete), a "show notes of subfolders too" toggle, the note count per folder, and the selected folder in the URL (`/folder/{path}`).

### 4.9 Tags (`notes.sqlite`)

Tags are stored where Desktop and Android already store them: `<notesPath>/notes.sqlite`. The server never keeps its own tag tables, so all three clients always agree.

**Safe access** (`TagDatabase`), following the Android rules from 3.2:

1. Requires the PHP `pdo_sqlite` extension. Without it, `tags.available = false` and the tag UI and endpoints are disabled (503 with a clear message), while everything else keeps working.
2. Read: take a shared lock on the file node, copy `notes.sqlite` to a local temp file (storage may be S3/object storage, so never open it in place), verify the header (`SQLite format 3\0`, bytes 18/19 == 1, no WAL), run `PRAGMA quick_check`, check required tables and columns, and read `appData.database_version`. The parsed result is cached per user, keyed by the file's ETag, so repeated reads cost one `stat`.
3. Write: only if the schema version is in the known writable range (15–16, the same as Android). Exclusive lock (`ILockingProvider`) → copy → open with `journal_mode=DELETE` → apply all operations in **one transaction** → verify the header again → write back **only if rows changed** and the file ETag still equals the one read at the start (otherwise retry once from the latest version, then 409/412). Never touch tables other than `tag` and `noteTagLink`. Never `VACUUM` or change the page size or encoding.
4. Missing `notes.sqlite`: reads return an empty tag set. The first write creates the file with the exact desktop schema version 16 (`repairNoteFolderSchema` DDL plus `appData('database_version','16')`) so Desktop opens it without migration. This is guarded by a per-user setting (default on) and covered by a compatibility test with a real Desktop build.
5. Unknown newer schema (> 16): tags are read-only (`tags.writable = false`). The UI shows a hint to update the server app.

**Domain logic** (`TagService`), mirroring `Tag` in Desktop:

- Hierarchical tags (`parent_id`), case-insensitive unique names per parent, `priority` and name ordering, `color`/`dark_color` (the web UI uses `dark_color` in dark themes and falls back to `color`), `updated` touched on the tag and its ancestors when linking.
- Links keyed by `(note_file_name, note_sub_folder_path)`. API responses map them to Notes API note IDs (file IDs) by resolving the path. Links whose note does not exist are returned under `stale` only by `GET /tag-links`.
- Stale handling as in `Tag::removeBrokenLinks`: links of missing notes get `stale_date`, the date is cleared when the note reappears, and links stale for more than 10 days are deleted. The server performs this only during writes it already does, never as a background job, to avoid unsolicited uploads that would cause sync conflicts with Desktop.
- Relinking for renames and moves performed **by this app** (web UI, subfolder API, opt-in Notes API header). Renames made through WebDAV or the Nextcloud Files UI are **not** relinked by a file event listener. QOwnNotes Desktop renames notes locally, updates its local `notes.sqlite` itself and syncs both. A concurrent server-side change to `notes.sqlite` would turn that into a sync conflict.

**API** (QOwnNotes API 1.1, all writes require `If-Match: <notes.sqlite ETag>` and return the new ETag):

- `GET /api/v1/tags` returns the tree `[{id, name, parentId, priority, color, darkColor, noteCount, children}]` with an `ETag` header.
- `POST /api/v1/tags` `{name, parentId?, color?, darkColor?, priority?}` creates a tag. `PATCH /api/v1/tags/{id}` renames, moves (re-parent with cycle check), changes color or priority. `DELETE /api/v1/tags/{id}?recursive=1` deletes the tag with its links (and children).
- `GET /api/v1/note/{id}/tags` / `PUT /api/v1/note/{id}/tags` `{tagIds: [...]}` or `{tagPaths: [["Work","Project A"]]}` gets or sets a note's tags. Tag paths are created if missing, like Android's `resolve(create = true)`.
- `GET /api/v1/tag-links` returns all links for a full client sync (`[{tagId, noteId|null, fileName, subFolderPath, stale}]`).
- Batch: `POST /api/v1/tags/batch` takes a list of operations in one transaction and one upload. This is what the web UI uses for multi-select tagging.

With these endpoints Android can later stop downloading and uploading the whole `notes.sqlite`. Until then, the WebDAV path keeps working because the server writes the same format with the same ETag discipline.

**Web UI**: a tag tree in the navigation (colors, counts, nested), filtering by one or more tags (AND/OR, "include child tags" like Desktop), an "untagged notes" entry, a tag combined with the selected subfolder ("tags in current subfolder"), tag chips on note list items, a tag editor in the note header with autocomplete and hierarchical paths, tag management (create, rename, move by drag and drop, color picker for light and dark, delete) and bulk tagging of multi-selected notes.

### 4.10 Further QOwnNotes conventions

- **File names and titles**: the file name (without suffix) is the note name. A new note's first line is the title as a heading. Creating a note through the web UI uses the QOwnNotes format (`# Title` by default, underlined title optional).
- **Media and attachments**: QOwnNotes stores images in `media/` and files in `attachments/` at the note folder root and links them relatively from the note's depth. Uploads via web UI or API go there, and the preview resolves these links.
- **Ignored files**: hidden files, files without a note suffix (e.g. `notes.sqlite`) and everything in ignored folders are not notes. Conflict copies with a note suffix are notes, like in QOwnNotes Desktop.
- **Suffixes**: `.md`, `.txt` plus custom suffixes (desktop allows `.markdown`, `.org`, `.note`, …). Default `.md`.
- **Favorites**: stored as the Nextcloud favorite file tag (`ITagManager`, `_$!<Favorite>!$_`), the same as Notes and Files, so favorites are shared. They are independent from QOwnNotes tags.

### 4.11 Data storage

- Notes and subfolders: files only.
- Tags: `<notesPath>/notes.sqlite` only (4.9).
- Table `qownnotes_meta` (`id`, `user_id`, `file_id`, `last_update`, `etag`, `content_etag`, `file_etag`) caches per-note ETags and modification state for `pruneBefore`, list ETags and cheap listing. It is a cache that can always be rebuilt from files. It is validated lazily against the file ETags on every request, so changes from any client are detected without file event listeners. Rows of deleted notes are removed while listing, and rows of deleted users by a `UserDeletedEvent` listener.
- Parsed `notes.sqlite` cache: `ICache` (distributed cache if configured), keyed by user and file ETag.
- User settings (`notesPath`, `fileSuffix`, `ignoreNoteSubFolders`, `subfoldersEnabled`, editor prefs) in `IConfig` user values under the `qownnotes` app ID. On first use, `notesPath` defaults to the user's Nextcloud Notes setting if present (`notes/notesPath`), otherwise `Notes`, so switching Android from Notes to QOwnNotes is seamless.

### 4.12 Web UI

- Vue 3, `@nextcloud/vue` 9, Pinia, Vue Router, built with Vite via `@nextcloud/vite-config`. Output into `js/` (git-ignored, built for releases).
- Layout: `NcContent` with navigation (subfolder tree, tag tree, favorites, search, settings), note list (sorted by modified or name, favorites first, tag chips), editor pane.
- Editor: CodeMirror 6 with Markdown mode and QOwnNotes-like highlighting (headings, checkboxes, links, code). Toggle for a markdown-it preview with task-list checkboxes and relative media resolution. Debounced autosave with ETag/`If-Match`, and a conflict dialog (keep mine / take theirs / show diff).
- Side panels: version history and trash (backed by the same services as the QOwnNotes API, with diff view), note info (path, size, modified, tags).
- Actions: create, rename (renames file and relinks tags), move between subfolders (drag and drop), delete (to trash), favorite, tag, insert image (upload to `media/`), copy WebDAV/share link (`OCS files_sharing`).
- Accessibility and keyboard shortcuts aligned with QOwnNotes Desktop where sensible (`Ctrl+N`, `Ctrl+F`, `Ctrl+Shift+F`).
- l10n via Nextcloud translation tooling (`l10n/` generated, never hand-edited).

### 4.13 Security

- Authentication through Nextcloud only (session, basic auth with app passwords, Android SSO). No own credentials.
- All path parameters (`notes_path`, `file_name`, `dir`, attachment `path`, `category`, subfolder `path`, tag `subFolderPath`) are normalized and checked to stay inside the user's folder (and the notes folder where applicable). This needs path traversal tests.
- `notes.sqlite` is untrusted input: only opened as a temp copy, with a size limit (configurable, default 50 MiB), `quick_check`, prepared statements only, no `ATTACH`, no extension loading, and the temp file deleted in `finally`.
- Respect share permissions (`readonly` on read-only shares, 403 on writes, tags read-only if `notes.sqlite` is not writable).
- `#[CORS]` only on API routes. Web routes stay CSRF protected.
- Rate-limit write endpoints with `#[UserRateLimit]` where it does not hurt sync. Brute-force protection is left to core auth.
- Output of `diffHtml` is generated by the server from escaped content. Clients render it, so escaping is tested.

## 5. Compatibility Targets

- Nextcloud 32–35 (same as qownnotesapi). The VM test covers 32–34 from NixOS 26.05 and adds 35 when it is packaged.
- PHP 8.2–8.5, with `pdo_sqlite` recommended (needed for tags).
- `notes.sqlite` schema versions: read 15–16+, write 15–16. Extend the range together with Desktop and Android when the desktop schema changes.
- Coexistence matrix tested in VM: {qownnotes} alone, {qownnotes + notes}, {qownnotes + qownnotesapi}.

## 6. Development Tooling

Already in this repository (ported from qownnotesapi):

| File                                                 | Purpose                                                                                                                                                                                                                             |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `devenv.nix`, `devenv.yaml`, `devenv.lock`, `.envrc` | devenv shell with `pbek/nix-shared` modules `common` (just, prek git hooks: prettier, shfmt, shellcheck, nixfmt, statix, deadnix, gitlint), `php` (PHP, php-cs-fixer hook) and `javascript` (Node, npm, eslint hook). zellij added. |
| `flake.nix`, `flake.lock`                            | `nixosTests.nextcloud-qownnotes`: NixOS VM test with Nextcloud 32, 33 and 34 nodes                                                                                                                                                  |
| `tests/vm/basic.nix`                                 | VM test. Currently asserts app enabled + `qownnotes` capability present + no `notes` capability                                                                                                                                     |
| `justfile`, `term.kdl`                               | `just vm-test`, `just vm-test-interactive`, `just format`, zellij session, patch helpers                                                                                                                                            |
| `.github/workflows/vm-test.yml`, `format-check.yml`  | CI for the VM test and formatting                                                                                                                                                                                                   |

Still to add (phase 0/1):

- `composer.json` with php-cs-fixer (`nextcloud/coding-standard`), psalm, phpunit, `nextcloud/ocp` (stubs per supported version). Add `.php-cs-fixer.dist.php` (required by the shared php-cs-fixer hook) and `psalm.xml`.
- ~~`package.json` with Vite, eslint (`@nextcloud/eslint-config`), stylelint, vitest.~~ Done (without stylelint).
- Done: `docker/` dev environment (official Nextcloud image instead of the pre-release image, with the signing script) and Playwright tests in `tests/e2e/`. Originally: `docker/` dev environment ported from qownnotesapi (Nextcloud pre-release image, port 8081, app mounted into `custom_apps`, signing script). Then extend `term.kdl` with the docker compose and `npm run watch` panes again.
- `tests/fixtures/notes-sqlite/`: real `notes.sqlite` files produced by QOwnNotes Desktop (schema 15 and 16, empty, with nested tags, with stale links, WAL-mode negative case, corrupt negative case) and by Android, plus a script to regenerate them with a pinned desktop build (`nix run nixpkgs#qownnotes` in headless/test mode, or the desktop integration tests).
- Done for the frontend (Composer has no runtime dependencies yet): Nix build of the app for the VM test including the frontend: `buildNpmPackage` with `npmDepsHash` for `js/` and a vendored or FOD Composer install, replacing the plain `runCommand` copy in `tests/vm/basic.nix`.
- VM test extension: port the qownnotesapi endpoint assertions (WebDAV `PUT`/`DELETE` + `note/*`) to `/apps/qownnotes/`, add a Notes API conformance script (create/list/chunk/prune/ETag/412/settings/attachments), subfolder operations, tag round trips (upload a fixture `notes.sqlite` via WebDAV → tag/rename/move via the API → download and verify with Python's `sqlite3`: schema untouched, header non-WAL, links relinked), and API-only-mode checks.
- Done: release tooling: `docker/sign-app.sh` (only the files listed in `release-files.txt` instead of an exclusion list, also used for the app in the VM test), `create_release.yml`, CalVer versioning, `AGENTS.md` (version location, release files). `term.kdl` has the docker compose and `npm run watch` panes again.

## 7. Phases

Each phase ends with green CI (format check + VM test).

Progress: phases 0–7 are implemented, phase 8 is prepared: release tooling (`release-files.txt`, `docker/sign-app.sh`, `just sign-app`, `create_release.yml`, `docs/release.md`), app store metadata and screenshots, API docs in `docs/api/`, `docs/notes-sqlite.md` and `docs/migration.md`. Still open for phase 8: the app store certificate and registration, the first app store release and the client releases from section 10. Phase 7 notes: the dashboard widget is an API widget (rendered by the dashboard app), unified search also matches tag paths (`#tag` searches only tags), the reference provider is only registered with the web interface, and the Files action is added through the public `BeforeTemplateRenderedEvent` and registered for both file action APIs (`@nextcloud/files` 3 for Nextcloud 32, 4 for 33+).

| Phase                                           | Scope                                                                                                                                                                                                                       | Exit criteria                                                                                                                                                                                                |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **0. Skeleton**                                 | `info.xml` (id `qownnotes`, NC 32–35), `Application` (IBootstrap), `Capabilities`, composer/npm/lint setup, docker dev env, `ui_enabled` app config key                                                                     | `just vm-test` green: app enabled, capability published, no `notes` key                                                                                                                                      |
| **1. Core services**                            | `NoteService` (CRUD, title/file mapping, suffixes), `SubFolderService` (tree, ignore rules, create/rename/move/delete on files), `SettingsService`, `MetaService` + migration + listeners, `FavoriteService`                | Unit tests for title sanitizing, path normalization, traversal rejection, ignore rules (fixtures from Desktop behavior), meta invalidation                                                                   |
| **2. Notes API**                                | `NotesApiController` v1.0–1.4 incl. chunking, prune, ETags, 412, settings, attachments, CORS, categories = subfolders                                                                                                       | VM conformance script passes. QOwnNotes Android (patched endpoint, see 10) performs full sync, edit, conflict, attachment and tag (WebDAV) flows against it                                                  |
| **3. QOwnNotes API 1.0**                        | Legacy `note/*` endpoints, `VersionService`, `TrashService`, diff HTML                                                                                                                                                      | Ported qownnotesapi VM assertions pass against `/apps/qownnotes/`. Desktop (patched endpoint) shows versions and trash and restores                                                                          |
| **4. Tags + subfolder API (QOwnNotes API 1.1)** | `TagDatabase` (safe read-modify-write), `TagService` (tree, links, relink, stale), `subfolders`/`tags`/`note/{id}/tags`/`tag-links`/batch endpoints, relinking in folder operations, opt-in relink header                   | Fixture-based unit tests for every operation. VM round-trip tests. Manual cross-check: tags changed on the web appear correctly in Desktop and Android, and vice versa, with no sync conflicts in normal use |
| **5. API-only mode**                            | Admin settings section, middleware, conditional navigation and integrations, capability flag                                                                                                                                | VM test covers both modes. APIs (incl. tags) unaffected when the UI is off                                                                                                                                   |
| **6. Web UI (MVP)**                             | Note list, subfolder tree with folder operations and drag and drop, tag tree with filtering, note tag editor, search, editor with autosave and conflict handling, preview, create/rename/move/delete/favorite, media upload | Playwright smoke tests (create → edit → reload; move note between folders keeps tags; tag filter). VM test builds frontend via Nix                                                                           |
| **7. Web UI (extended)**                        | Tag management (colors, re-parenting, bulk tagging), versions and trash panels, dashboard widget, unified search (incl. tag search), reference provider, Files "Open in QOwnNotes" action                                   | Feature tests per integration. All integrations disappear in API-only mode                                                                                                                                   |
| **8. Release**                                  | Signing, app store listing, docs (README, API docs under `docs/api/` for Notes compatibility, QOwnNotes API 1.0/1.1, `notes.sqlite` handling), migration guide                                                              | App store release. Client releases from section 10 shipped                                                                                                                                                   |

## 8. Testing Strategy

- **PHP unit** (phpunit): services with mocked OCP interfaces, path and title edge cases, ignore-rule regexes, chunk cursor encoding, diff HTML snapshots, and `TagDatabase`/`TagService` against the `notes.sqlite` fixtures (header and WAL checks, schema-version gating, prefix-only relink, stale lifecycle, case-insensitive lookup, ancestor `updated` touch, no-change → no upload).
- **API integration**: Guzzle-based tests against the docker dev server (`tests/integration`), mirroring the Notes API v1 spec, the qownnotesapi responses and the QOwnNotes API 1.1 contract.
- **NixOS VM test**: multi-version Nextcloud (32–34+), real WebDAV + API calls, `notes.sqlite` round trips verified with Python `sqlite3`, coexistence with `notes`/`qownnotesapi`, API-only mode. This is the main regression gate.
- **Concurrency tests**: two parallel tag writes (one wins, the other retries or gets 412), a WebDAV upload of `notes.sqlite` during an API write, and a lock timeout.
  Unit tests simulate external database changes during a write and verify that retries preserve those changes, explicit `If-Match` rejects them, repeated races stop after one retry, and locks are released on success and failure. A failed lock acquisition never starts a write. Real parallel WebDAV/API races remain follow-up work.
- **Frontend**: vitest for stores and utilities (tag tree, filter logic, relative media link rewriting), Playwright for E2E smoke tests against docker.
- **Client contract tests**: request templates transcribed from pinned versions of `NextcloudBackend.kt` and `cloudservice.cpp` live in `tests/fixtures/client-contracts/` and are replayed in the multi-version VM test (including Android's `internalPath` dependency for versions). These are synthetic fixtures, not network recordings. Recorded tag-file requests and real Desktop/Android database fixtures remain follow-up work.

## 9. `notes.sqlite` Concurrency Model

The file is written by up to three parties: QOwnNotes Desktop (locally, synced via the Nextcloud desktop client), Android (WebDAV `PUT` with `If-Match`), and this app (in-process, with lock and ETag check).

- The server and Android are serialized through the Nextcloud file ETag. Whoever writes second sees a mismatch and re-applies its operations to the newer file.
- Desktop writes arrive as whole-file uploads from the sync client. If the server wrote in between, the Nextcloud desktop client creates a conflict copy. That is the same risk that exists today with Android. Mitigations: write only when rows change, batch UI operations (debounced, single upload), never write from background jobs or file listeners, and keep every write small and quick.
- Future improvement (outside this app): Desktop could merge conflict copies of `notes.sqlite` by replaying the link differences. Track this as a Desktop issue.

## 10. Client Changes and Migration

### QOwnNotes Android (`backend-nextcloud`)

1. Read capabilities. If `qownnotes` is present and `notes_api_version` contains a supported 1.x (≥1.2), use `qownnotes.api_base` for **both** `NotesApi` and `QOwnNotesApi`.
2. Otherwise fall back to today's behavior (`/apps/notes/api/v1/` + `/apps/qownnotesapi/api/v1/`, `notes` capability required).
3. `validateCapabilities` must no longer throw `NotesAppMissing` when only `qownnotes` exists.
4. Attachment URLs (`MarkdownRenderer.kt`, `AttachmentOpener.kt`) must use the resolved base instead of hardcoded `/apps/notes/api/v1.4/attachment/`.
5. Store the resolved base per account and re-resolve on capability refresh. A base change triggers a full pull, like a `notesPath` change does.
6. Later (optional): when `qownnotes_api_version >= 1.1` and `tags.writable`, switch tag sync from whole-file WebDAV to the tags API, and send `X-QOwnNotes-Relink-Tags: 1` instead of relinking locally.

### QOwnNotes Desktop (`src/services/cloudservice.cpp`)

1. Replace the static `rootPath` with a value resolved from `/ocs/v1.php/cloud/capabilities`: `qownnotes.api_base` if present, else `/index.php/apps/qownnotesapi/api/v1/`.
2. Settings dialog: show which server app was detected ("QOwnNotes" or "QOwnNotesAPI") and link to the new app in the "app missing" hint.
3. When the `notes.sqlite` schema changes in Desktop, bump the writable range in this app and Android together (shared compatibility table in `docs/`).

### qownnotesapi

- Stays maintained (security and Nextcloud compatibility) for at least a transition period of about 12 months after both client updates ship. It stays the only option for ownCloud.
- A final feature release adds an admin notice recommending the `qownnotes` app.
- Both apps can be installed simultaneously. They share no DB tables or routes.

### Nextcloud Notes app

- Can stay installed. Both apps operate on files, and favorites are shared via file tags.
- Users who only kept Notes for QOwnNotes Android can uninstall it once their Android client supports `qownnotes`. `notesPath` is taken over automatically (4.11).

## 11. Risks and Open Questions

| Risk / question                                                                    | Mitigation / decision needed                                                                                    |
| ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Clients need updates before the new app is useful                                  | Ship client changes with fallback early (they are small). Server and clients can be released independently      |
| Concurrent edits from desktop (file sync) and web/Android                          | ETag/`If-Match` on all writes. The conflict UI in the web editor. Never auto-merge silently                     |
| `notes.sqlite` sync conflicts with Desktop                                         | See section 9. Minimal, batched, change-only writes. No background writes                                       |
| `notes.sqlite` schema changes in Desktop                                           | Version-gated writes (15–16). Read-only fallback. Shared compatibility table across Desktop, Android and server |
| `pdo_sqlite` missing on some servers                                               | Tags feature degrades to unavailable and is reported in capabilities and the admin settings page                |
| Large `notes.sqlite` files (many links)                                            | ETag-keyed parse cache, size limit, temp copy only on change                                                    |
| WebDAV/Files renames are not relinked by the server                                | Intentional (section 4.9). Desktop and Android relink their own renames. Document this                          |
| Notes API semantic drift vs. upstream Notes                                        | Track upstream `docs/api/v1.md`. Contract tests. Only implement versions we test                                |
| `IVersionManager`/`ITrashManager` behavior differs between NC versions             | VM test across all supported versions. Graceful degradation (`versions_app: false`)                             |
| `diffHtml` format expectations in Desktop                                          | Snapshot tests. Consider adding a structured diff in a future API version                                       |
| Open: create `notes.sqlite` server-side when missing (4.9 item 4)?                 | Proposed: yes, with the exact desktop v16 schema, behind a per-user setting (default on)                        |
| Open: rewrite relative media links when moving notes between subfolder depths?     | Proposed: yes, option default on, matching Desktop                                                              |
| Open: should API-only mode also be selectable per user, or only by the admin?      | Proposed: admin global switch now, per-user "hide from navigation" later                                        |
| Open: default note format for web-created notes (`# Title` vs. underlined title)?  | Proposed: `# Title`, matching QOwnNotes default                                                                 |
| Open: minimum Nextcloud version 32 (like qownnotesapi) or 33 (like current Notes)? | Proposed: 32                                                                                                    |
