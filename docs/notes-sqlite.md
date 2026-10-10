# Tags and `notes.sqlite`

QOwnNotes stores the tags of a note folder in the SQLite database `notes.sqlite` in the note folder. QOwnNotes Desktop writes it locally and the Nextcloud desktop client syncs it, QOwnNotes Android downloads and uploads it via WebDAV. This app reads and writes the same file, so the tags are the same in all clients. The server keeps no copy of the tags in its own database.

## Data model

The server only uses the tables `appData`, `tag` and `noteTagLink` of the QOwnNotes Desktop schema (version 16):

- `tag`: `id`, `name` (unique per parent, case-insensitive), `parent_id` (`0` for top level tags), `priority`, `color`, `dark_color`, `created`, `updated`.
- `noteTagLink`: `tag_id`, `note_file_name` (file name with suffix, e.g. `Meeting.md`), `note_sub_folder_path` (`/`-separated, `""` for the root), `created`, `stale_date`.

Notes are identified by their file name and subfolder path only. There are no IDs shared with the files, so the links have to be updated whenever a note is renamed or moved.

## Behavior

The server behaves like QOwnNotes Desktop:

- **Renaming or moving a note** moves its links to the new file name and subfolder. Links that already exist at the new location are merged.
- **Renaming or moving a subfolder** replaces the prefix of the subfolder paths of all links in it and its subfolders. Only exact path segments are matched, so renaming `work` doesn't change `workplace`.
- **Deleting a note or subfolder** marks the links as stale (`stale_date`). QOwnNotes Desktop deletes stale links after 10 days. If the note comes back within that time, e.g. restored from the trash, the links become active again.
- **Linking a tag** also updates the `updated` time of the tag and its parent tags, which QOwnNotes uses to order recently used tags.
- **Deleting a tag** deletes its child tags and all their links.

The server updates links only for changes made through this app: the web interface, the subfolder API and Notes API requests with `X-QOwnNotes-Relink-Tags: 1` (see the [Notes API](api/notes-api.md#tags-x-qownnotes-relink-tags)). Notes renamed through WebDAV or the Files app are **not** relinked, because QOwnNotes Desktop and Android relink their own renames and upload `notes.sqlite` themselves. A server-side change at the same time would cause sync conflicts. For the same reason the server never changes `notes.sqlite` in the background, e.g. to delete old stale links.

## Safe access

`notes.sqlite` is an untrusted file in the user's storage, which may also be on object storage. The server follows the rules of QOwnNotes Android:

1. The file is never opened in place. The server copies it to a temporary file and deletes the copy afterwards, including when the file cannot be read or fails validation.
2. Before use, the copy is checked: it must be an SQLite 3 database in rollback-journal mode (no write-ahead logging), pass `PRAGMA quick_check`, have the required tables and columns, a schema version (`appData.database_version`) of at least 15, and be at most 50 MiB.
   The size limit is enforced on the copied bytes as well as the storage metadata, so stale size information cannot bypass it.
3. Parsed tags are cached per file ETag, so repeated reads only cost a file `stat`.
4. Changes are only made for schema versions 15 and 16. Files with a newer version are read-only (`writable: false`), until the server app supports that version.
5. A change takes an exclusive lock, applies all operations of the request in one transaction with `journal_mode=DELETE`, checks the header again and uploads the file **only if rows changed**. Other tables like `trashItem` are never touched, and the file is never vacuumed or converted.
6. If another client changed the file while the server worked on it, the server applies the change again to the new file once, and otherwise fails with 412. API clients can send `If-Match` with the ETag they know to make sure they don't overwrite changes they haven't seen.
7. If `notes.sqlite` doesn't exist, the tags are empty. The first tag change creates the file with the exact schema of QOwnNotes Desktop version 16, so QOwnNotes opens it without migration. Users can turn this off with the setting `createTagDatabase`.

The server needs the PHP extension `pdo_sqlite` for tags. Without it, the capability `tags.available` is `false`, the tag endpoints answer with 503, the admin settings show a hint, and everything else keeps working.

## Sync conflicts with QOwnNotes Desktop

QOwnNotes Desktop changes `notes.sqlite` locally and the Nextcloud desktop client uploads the whole file. If the server changed the file in between, the desktop client creates a conflict copy, which is the same risk that exists with QOwnNotes Android today. The server keeps this risk small by writing only when something changed, writing all changes of an action at once (e.g. tagging several notes) and never writing in the background.

## Compatibility

| `notes.sqlite` schema version | Read | Write |
| ----------------------------- | ---- | ----- |
| < 15                          | no   | no    |
| 15, 16                        | yes  | yes   |
| > 16                          | yes  | no    |

When QOwnNotes Desktop changes the schema, the writable versions of this app (`TagDatabase::WRITABLE_SCHEMA_VERSIONS`) and of QOwnNotes Android have to be extended together.

## Compatibility checks

[`tests/fixtures/notes-sqlite/`](../tests/fixtures/notes-sqlite/README.md) contains a real database created by QOwnNotes Desktop 26.10.2 and clearly labeled synthetic data/negative variants. PHP tests and VM WebDAV round trips verify that server writes preserve the Desktop schema, page size, encoding and trash data. Parallel VM API/API and API/WebDAV writes using the same file ETag verify that only one succeeds, with no lost or partial batch changes. Genuine schema-15 and Android-written captures and recorded client requests remain follow-up validation.
