# QOwnNotes API

The QOwnNotes API complements the [Notes API](notes-api.md) with the features of QOwnNotes that the Notes API can't express: note versions, the trash, note subfolders and the tags of the note folder database `notes.sqlite`.

- **Version 1.0** are the endpoints of the [qownnotesapi](https://github.com/pbek/qownnotesapi) app, with the same parameters and responses, used by QOwnNotes Desktop and Android.
- **Version 1.1** adds subfolders, tags, and note details, versions and trash by note ID.

See the [API overview](README.md) for the base URL, authentication, capabilities and error codes.

## QOwnNotes API 1.0

These endpoints are compatible with the qownnotesapi app. They answer errors with HTTP status 200 and report them in the body, as before.

| Method        | Path                   | Parameters                                                  |
| ------------- | ---------------------- | ----------------------------------------------------------- |
| `GET`         | `note/app_info`        | `notes_path`                                                |
| `GET`         | `note/versions`        | `file_name`                                                 |
| `GET`         | `note/trashed`         | `dir`, `extensions[]`, `sort`, `sortdirection`, `recursive` |
| `GET`, `POST` | `note/restore_trashed` | `file_name`, `timestamp`                                    |

All paths (`notes_path`, `file_name`, `dir`) are relative to the user's files root. `.` and `..` are resolved, so they can't point outside of it.

### `GET note/app_info`

```json
{
  "user": "alice",
  "versions_app": true,
  "trash_app": true,
  "versioning": true,
  "app_version": "26.10.0",
  "server_version": "33.0.2.2",
  "notes_path_exists": true
}
```

`notes_path_exists` tells whether `notes_path` is an existing folder. The CalVer `app_version` satisfies the minimum versions that QOwnNotes Desktop (0.4.2) and Android (0.4.4) check.

### `GET note/versions`

Previous versions of a file (newest first), excluding the current one:

```json
{
  "file_name": "Notes/Meeting.md",
  "versions": [
    {
      "timestamp": 1791273600,
      "humanReadableTimestamp": "2 hours ago",
      "diffHtml": "# Meeting\n\n<del>Agenda</del><ins>Topics</ins>",
      "data": "# Meeting\n\nTopics"
    }
  ],
  "error_messages": []
}
```

`diffHtml` shows the differences from the current note to the version as HTML-escaped text with `<del>` and `<ins>` tags, the format the version dialog of QOwnNotes Desktop expects. If the file doesn't exist, `error_messages` contains `Requested file was not found!`.

### `GET note/trashed`

Deleted notes of a folder (default: newest first):

```json
{
  "directory": "Notes",
  "notes": [
    {
      "noteName": "Meeting",
      "fileName": "Meeting.md",
      "timestamp": 1791273600,
      "dateString": "October 7, 2026 at 10:00:00 AM GMT+2",
      "data": "# Meeting\n\n…",
      "originalLocation": "Notes/Meeting.md"
    }
  ]
}
```

- `extensions[]` adds file extensions to `md` and `txt`.
- `sort=name` sorts by file name instead of the deletion time, `sortdirection=asc` sorts ascending.
- `recursive=1` also returns notes deleted from subfolders of `dir`. `originalLocation` is the path of the note before it was deleted. Both are additions of this app.

### `GET`/`POST note/restore_trashed`

Restores the deleted file `file_name` (file name or original path) that was deleted at `timestamp`:

```json
{ "result": true, "path": "//Meeting.md.d1791273600", "filename": "Meeting.md" }
```

## QOwnNotes API 1.1

The endpoints of version 1.1 use HTTP status codes for errors and the note IDs of the Notes API.

### Subfolders

The note subfolders form a tree below the note folder, including empty folders, which the Notes API can't represent. The ignore rules of the [Notes API](notes-api.md#note-files-and-subfolders) apply.

| Method   | Path         | Parameters        | Description                          |
| -------- | ------------ | ----------------- | ------------------------------------ |
| `GET`    | `subfolders` |                   | The subfolder tree                   |
| `POST`   | `subfolders` | `path`            | Create a subfolder (and its parents) |
| `PATCH`  | `subfolders` | `path`, `newPath` | Rename or move a subfolder           |
| `DELETE` | `subfolders` | `path`            | Delete a subfolder (to the trash)    |

A tree node:

```json
{
  "path": "Work/Projects",
  "name": "Projects",
  "noteCount": 3,
  "noteCountRecursive": 5,
  "readonly": false,
  "mtime": 1791273600,
  "children": []
}
```

`GET subfolders` returns the root node (`path` and `name` are `""`). `POST` returns the new node. `PATCH` returns `{"folder": {…}, "tagsRelinked": true}`; the tag links of all notes in the folder are moved to the new path in `notes.sqlite`. `DELETE` returns `{"tagsUpdated": true}`; the tag links of the notes in the folder become stale, so they come back if the folder is restored within 10 days. `tagsRelinked` and `tagsUpdated` are `false` if `notes.sqlite` couldn't be changed; the folder operation itself succeeded anyway.

### Tags

Tags are read from and written to `<notesPath>/notes.sqlite`, the note folder database of QOwnNotes Desktop and Android, see [notes-sqlite.md](../notes-sqlite.md).

| Method   | Path             | Description                                        |
| -------- | ---------------- | -------------------------------------------------- |
| `GET`    | `tags`           | The tag tree                                       |
| `POST`   | `tags`           | Create a tag                                       |
| `PATCH`  | `tags/{id}`      | Rename, move or recolor a tag, change its priority |
| `DELETE` | `tags/{id}`      | Delete a tag with its child tags and their links   |
| `POST`   | `tags/batch`     | Several changes in one transaction and one upload  |
| `GET`    | `tag-links`      | All links between tags and notes                   |
| `GET`    | `note/{id}/tags` | The tags of a note                                 |
| `PUT`    | `note/{id}/tags` | Set the tags of a note                             |

**ETags**: every response has the field `etag` with the ETag of `notes.sqlite` (`null` if the file doesn't exist). Changes accept an `If-Match` header with this ETag and fail with 412 (`{"etag": "<current>"}`) if somebody else changed the file in the meantime. Without `If-Match`, the change is applied to the current file. `GET` responses also have an HTTP `ETag` header and answer `If-None-Match` with 304.

#### `GET tags`

```json
{
  "etag": "6a1f…",
  "schemaVersion": 16,
  "writable": true,
  "tags": [
    {
      "id": 1,
      "name": "Work",
      "parentId": 0,
      "priority": 0,
      "color": "#3584e4",
      "darkColor": "#99c1f1",
      "noteCount": 4,
      "children": []
    }
  ]
}
```

- `writable` is `false` if the schema version of `notes.sqlite` is newer than the server supports. The tags can still be read.
- `noteCount` counts the links of the tag that are not stale.
- `color` and `darkColor` are `#rrggbb` or `#rrggbbaa`, or `null`. QOwnNotes uses `darkColor` in dark mode and falls back to `color`.
- Tags are sorted by `priority` and name. Tags whose parent doesn't exist are shown at the top level.

#### `POST tags`, `PATCH tags/{id}`, `DELETE tags/{id}`

`POST tags` takes `{name, parentId?, priority?, color?, darkColor?}`. `PATCH tags/{id}` takes any of these fields; a `parentId` of `0` moves the tag to the top level, and a tag can't be moved into itself or its descendants. Names are unique per parent tag and compared case-insensitively. A `darkColor` that is not given on creation is set to `color`.

Both return `{"etag": "…", "tag": {"id", "name", "parentId", "priority", "color", "darkColor", "path"}}`, where `path` is the list of tag names from the top level, e.g. `["Work", "Projects"]`. `DELETE` returns `{"etag": "…"}`.

#### `GET note/{id}/tags`, `PUT note/{id}/tags`

```json
{
  "etag": "6a1f…",
  "tags": [
    {
      "id": 2,
      "name": "Projects",
      "path": ["Work", "Projects"],
      "color": null,
      "darkColor": null
    }
  ]
}
```

`PUT` sets the tags of the note to exactly the given tags, either by ID (`{"tagIds": [1, 2]}`) or by path (`{"tagPaths": [["Work", "Projects"], "Private/Ideas"]}`). Tags of paths that don't exist are created.

#### `POST tags/batch`

Applies a list of operations in one transaction. Either all of them succeed or none:

```json
{
  "operations": [
    { "op": "link", "noteId": 1234, "tagId": 2 },
    { "op": "link", "noteId": 1235, "tagPath": ["Work", "Projects"] },
    { "op": "unlink", "noteId": 1234, "tagId": 3 },
    { "op": "create", "name": "Ideas", "parentId": 0, "color": "#e01b24" },
    { "op": "update", "id": 4, "name": "Archive" },
    { "op": "delete", "id": 5 }
  ]
}
```

`link` creates missing tags of a `tagPath`. The response has the format of `GET tags`.

#### `GET tag-links`

All links, for clients that sync the tags completely:

```json
{
  "etag": "6a1f…",
  "links": [
    {
      "tagId": 2,
      "noteId": 1234,
      "fileName": "Meeting.md",
      "subFolderPath": "Work",
      "stale": false
    }
  ]
}
```

`noteId` is `null` if the note doesn't exist (anymore). `stale` links belong to notes that were deleted or are missing; QOwnNotes deletes them after 10 days.

### Note details, versions and trash

These endpoints address notes by their ID and only return notes of the note folder.

| Method | Path                 | Description                      |
| ------ | -------------------- | -------------------------------- |
| `GET`  | `note/{id}/info`     | File details of a note           |
| `GET`  | `note/{id}/versions` | Previous versions of a note      |
| `GET`  | `trash`              | Deleted notes of the note folder |
| `POST` | `trash/restore`      | Restore a deleted note           |

`GET note/{id}/info`:

```json
{
  "id": 1234,
  "fileName": "Meeting.md",
  "subFolderPath": "Work",
  "path": "Notes/Work/Meeting.md",
  "size": 1520,
  "modified": 1791273600,
  "readonly": false,
  "versionsAvailable": true,
  "trashAvailable": true
}
```

`GET note/{id}/versions` returns `{"id": 1234, "versions": […]}` with the versions in the format of `note/versions`. It fails with 503 if the Versions app is disabled.

`GET trash` returns the notes deleted from the note folder and its subfolders that are not ignored, newest first:

```json
{
  "notes": [
    {
      "title": "Meeting",
      "fileName": "Meeting.md",
      "subFolderPath": "Work",
      "originalLocation": "Notes/Work/Meeting.md",
      "deleted": 1791273600,
      "content": "# Meeting\n\n…"
    }
  ]
}
```

`POST trash/restore` takes `{originalLocation, deleted}` of such a note and returns `{"id": 1234}`, the ID of the restored note (`null` if it was restored to a place where it is no note, e.g. an ignored folder). With the header `X-QOwnNotes-Relink-Tags: 1`, the stale tag links of the note become active again.
