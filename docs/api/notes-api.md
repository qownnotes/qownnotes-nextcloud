# Notes API 1.4

The app implements the [Nextcloud Notes API v1](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md) up to version 1.4 for QOwnNotes Android. Requests and responses have the same format as in the Nextcloud Notes app, so this document only lists the endpoints and the places where the behavior follows QOwnNotes instead of Nextcloud Notes.

See the [API overview](README.md) for the base URL, authentication, capabilities and error codes.

## Endpoints

| Method   | Path                        | Description                                                                   |
| -------- | --------------------------- | ----------------------------------------------------------------------------- |
| `GET`    | `notes`                     | List notes (`category`, `exclude`, `pruneBefore`, `chunkSize`, `chunkCursor`) |
| `POST`   | `notes`                     | Create a note (`category`, `title`, `content`, `modified`, `favorite`)        |
| `GET`    | `notes/{id}`                | Get a note (`exclude`)                                                        |
| `PUT`    | `notes/{id}`                | Update a note (`content`, `title`, `category`, `modified`, `favorite`)        |
| `DELETE` | `notes/{id}`                | Delete a note                                                                 |
| `GET`    | `settings`                  | Get the user's settings                                                       |
| `PUT`    | `settings`                  | Change the user's settings                                                    |
| `GET`    | `attachment/{noteId}?path=` | Download a file linked by a note                                              |
| `POST`   | `attachment/{noteId}`       | Upload a file (multipart field `file`)                                        |
| `DELETE` | `attachment/{noteId}?path=` | Delete a media file or attachment                                             |

The attachment endpoints are also available as `/api/v1.4/attachment/{noteId}`.

## Notes

A note has the attributes of the Notes API:

```json
{
  "id": 1234,
  "etag": "6c0b1e5f3a9d…",
  "readonly": false,
  "modified": 1791273600,
  "title": "Meeting",
  "category": "Work/Projects",
  "favorite": false,
  "content": "# Meeting\n\n…",
  "internalPath": "/Notes/Work/Projects/Meeting.md"
}
```

- `id` is the Nextcloud file ID, so it stays the same when a note is renamed or moved.
- `title` is the file name without the suffix, e.g. `Meeting` for `Meeting.md`.
- `category` is the note subfolder path relative to the note folder, separated by `/`, and `""` for the note folder root.
- `favorite` is the Nextcloud favorite of the file, shared with the Files and Notes apps.
- `internalPath` is a read-only QOwnNotes extension: the actual file path inside the requesting user's files root, including the note folder, suffix and a leading `/` (not the server's filesystem path). Android requires this slash and passes the path to the legacy `note/versions` endpoint. It follows renames and moves and can be omitted with `exclude=internalPath`.
- `etag` changes whenever one of the other attributes changes.

## Differences to Nextcloud Notes

### Note files and subfolders

- Notes are the files with the suffixes `.md`, `.txt` and the user's `fileSuffix` in the note folder and its subfolders. Hidden files and files with other suffixes (like `notes.sqlite`) are not notes. Conflict copies with a note suffix are notes, like in QOwnNotes Desktop.
- Subfolders are ignored like in QOwnNotes Desktop: always `media`, `attachments` and `trash`, plus the folders matching the regular expressions of the setting `ignoreNoteSubFolders` (default `^\.`, hidden folders). Notes in ignored subfolders don't exist for the API, and creating or moving notes into them fails with 400.
- If the setting `subfoldersEnabled` is off, only the notes of the note folder root are listed.

### Titles and file names

- Titles are sanitized like in QOwnNotes Desktop: `/`, `\` and `:` are removed, other characters that are invalid in file names become spaces, leading dots are removed and the length is limited to 200 characters. An empty title becomes `Note`.
- If the file name is taken, a number is appended like in QOwnNotes Desktop (`Meeting 1.md`, `Meeting 2.md`), comparing names case-insensitively.
- `POST notes` without `title` takes the title from the first line of `content`.
- The server never renames a note because its first line changed. Clients send `title` with `PUT` to rename it.

### Updates and conflicts

- `PUT notes/{id}` with `If-Match: "<etag>"` fails with 412 if the note was changed, and the body contains the current note.
- `GET notes` and `GET notes/{id}` answer `If-None-Match` with 304.
- `DELETE notes/{id}` moves the file to the Nextcloud trash if the Deleted files app is enabled.

### Tags (`X-QOwnNotes-Relink-Tags`)

The Notes API doesn't change `notes.sqlite` by default, because QOwnNotes Android currently relinks the tags of renamed and moved notes itself and uploads `notes.sqlite` with `If-Match`. A server-side change would make that upload fail.

Clients that use the [tags API](qownnotes-api.md#tags) instead can send the header `X-QOwnNotes-Relink-Tags: 1`:

- `PUT notes/{id}` that renames or moves a note moves its tag links in `notes.sqlite` to the new file name and subfolder, and adapts relative links to media files and attachments to the new subfolder depth (`media/image.png` becomes `../media/image.png`; can be turned off with the setting `rewriteMediaLinks`).
- `DELETE notes/{id}` marks the note's tag links as stale. QOwnNotes deletes stale links after 10 days and revives them if the note comes back (see `trash/restore`).

The web interface always sends this header.

### Listing in chunks

`GET notes` supports `pruneBefore`, `chunkSize` and `chunkCursor` like Notes API 1.2:

- Notes are sent in the order of their last change. The response of a chunk has the headers `X-Notes-Chunk-Cursor` (pass it as `chunkCursor` to get the next chunk) and `X-Notes-Chunk-Pending` (number of notes in the following chunks).
- The last chunk also contains all other notes with only their `id`, so clients can detect deleted notes.
- Notes that didn't change since `pruneBefore` only contain their `id`.
- The cursor is opaque. An invalid cursor is answered with 400.

### Settings

`GET settings` returns the settings of the Notes API and additional QOwnNotes settings. `PUT settings` only changes the given keys and ignores unknown keys, so Notes clients can send their usual `notesPath` and `fileSuffix`.

| Key                    | Default | Description                                                                                          |
| ---------------------- | ------- | ---------------------------------------------------------------------------------------------------- |
| `notesPath`            | `Notes` | The note folder, relative to the user's files root; `.` and `..` are resolved, the folder is created |
| `fileSuffix`           | `.md`   | Suffix of new notes, e.g. `.md`, `.txt` or a custom suffix like `.markdown`                          |
| `ignoreNoteSubFolders` | `^\.`   | `;`-separated regular expressions of subfolder names to ignore, like in QOwnNotes Desktop            |
| `subfoldersEnabled`    | `true`  | Whether notes in subfolders are shown                                                                |
| `noteHeaderStyle`      | `atx`   | Headline of notes created by the web interface: `atx` (`# Title`) or `setext` (underlined title)     |
| `createTagDatabase`    | `true`  | Whether the server may create a missing `notes.sqlite` when tags are changed                         |
| `rewriteMediaLinks`    | `true`  | Whether relative media links are adapted when a note is moved to another subfolder depth             |

If the user never set `notesPath`, the app takes the note folder of the Nextcloud Notes app, so both apps show the same notes.

### Attachments

- `GET attachment/{noteId}?path=…` resolves `path` relative to the note's subfolder, like the links in the note (`../media/image.png`). Paths outside the note folder are answered with 404.
- `POST attachment/{noteId}` stores images in the `media` folder and other files in the `attachments` folder at the note folder root, like QOwnNotes Desktop, and appends a number if the name is taken. The response `{"filename": "../media/image.png"}` contains the link relative to the note's subfolder, ready to be used in the note.
- `DELETE attachment/{noteId}?path=…` only deletes files in the `media` and `attachments` folders.

## Not supported

- Notes API v0.2.
- The `notes` capability. Clients find the API through the `qownnotes` capability, see the [API overview](README.md#capabilities).
