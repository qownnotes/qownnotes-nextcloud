# QOwnNotes for Nextcloud: APIs

The app serves two APIs under one base URL:

| API                                     | Used by                               | Documentation                        |
| --------------------------------------- | ------------------------------------- | ------------------------------------ |
| Notes API 1.4 (Nextcloud Notes API v1)  | QOwnNotes Android                     | [notes-api.md](notes-api.md)         |
| QOwnNotes API 1.0 (qownnotesapi compat) | QOwnNotes Desktop, QOwnNotes Android  | [qownnotes-api.md](qownnotes-api.md) |
| QOwnNotes API 1.1 (subfolders, tags, …) | Web interface, future client versions | [qownnotes-api.md](qownnotes-api.md) |

How the tags in `notes.sqlite` are read and written is described in [notes-sqlite.md](../notes-sqlite.md).

## Base URL

```
https://<server>/index.php/apps/qownnotes/api/v1/
```

All endpoints of both APIs live below this path, so clients only need one base URL. Attachments are also served under `/api/v1.4/attachment/{noteId}`, the path that Notes API 1.4 clients use.

The API stays available when an administrator disables the web interface (API-only mode).

## Authentication

Requests are authenticated by Nextcloud: HTTP basic authentication with the user name and an app password (recommended), Nextcloud Single Sign-On on Android, or the browser session plus the `requesttoken` header (the web interface). The app has no own credentials.

All endpoints allow CORS requests. The preflight `OPTIONS` requests are answered for all paths below `/api/v1/` and `/api/v1.4/`.

## Capabilities

Clients detect the app with the OCS capabilities (`/ocs/v1.php/cloud/capabilities`). The app publishes them under the `qownnotes` key only:

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

| Field                           | Meaning                                                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `version`                       | Version of the app (CalVer)                                                                                         |
| `notes_api_version`             | Supported Notes API versions                                                                                        |
| `qownnotes_api_version`         | Supported QOwnNotes API versions; 1.1 includes 1.0                                                                  |
| `api_base`                      | Base path of both APIs, relative to the server URL                                                                  |
| `ui_enabled`                    | Whether the web interface is enabled; clients can hide "Open in browser" actions if it is not                       |
| `notes_path`                    | The user's note folder, relative to their files root (`null` without a logged-in user)                              |
| `versions_app`, `trash_app`     | Whether the Versions and Deleted files apps are enabled for the user                                                |
| `tags.available`                | Whether the server can read `notes.sqlite` (needs the PHP extension `pdo_sqlite`)                                   |
| `tags.writable_schema_versions` | `notes.sqlite` schema versions the server can modify; whether the user's file is writable is reported by `GET tags` |

The app does **not** publish a `notes` capability, because that would collide with the Nextcloud Notes app. Clients that find `qownnotes` should use `api_base` for the Notes API and the QOwnNotes API, see the [migration guide](../migration.md).

## Common response headers

Every API response contains

- `X-Notes-API-Versions: 1.4`
- `X-QOwnNotes-API-Versions: 1.1`

## Errors

The Notes API and the QOwnNotes API 1.1 use HTTP status codes:

| Status | Meaning                                                                                                                                                                     |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 304    | `If-None-Match` matched, the client already has the current state                                                                                                           |
| 400    | Invalid input, e.g. an ignored subfolder, an invalid chunk cursor or an invalid tag name                                                                                    |
| 403    | The note, folder or `notes.sqlite` is read-only (e.g. a read-only share)                                                                                                    |
| 404    | The note, subfolder or attachment doesn't exist                                                                                                                             |
| 412    | `If-Match` didn't match; the body contains the current state (the note, or the `notes.sqlite` ETag)                                                                         |
| 423    | The file is locked; the server already retried a few times                                                                                                                  |
| 500    | Unexpected error                                                                                                                                                            |
| 503    | Tags are unavailable: the PHP extension `pdo_sqlite` is missing, or `notes.sqlite` can't be used (damaged, write-ahead logging, schema version not writable; see `message`) |
| 507    | Not enough storage space                                                                                                                                                    |

Error responses have the body `{"errorType": "NoteNotFoundException", "message": "…"}`. The message is only filled for errors of the app itself.

The QOwnNotes API 1.0 endpoints keep the lenient error handling of the qownnotesapi app: they answer with status 200 and report errors in the body.

## Versioning

- The Notes API follows the [Nextcloud Notes API v1](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md) and only lists versions that are tested.
- QOwnNotes API 1.0 is the endpoint set of the qownnotesapi app. 1.1 adds subfolders, tags, note details, versions and trash by note ID. New fields may be added to responses in any version, so clients must ignore unknown fields.
