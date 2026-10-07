# Migration guide

QOwnNotes for Nextcloud (app ID `qownnotes`) replaces two server apps for QOwnNotes users:

- the [QOwnNotesAPI](https://github.com/pbek/qownnotesapi) app (`qownnotesapi`), which QOwnNotes Desktop and Android use for note versions and the trash,
- the [Nextcloud Notes](https://github.com/nextcloud/notes) app (`notes`), which QOwnNotes Android uses to sync notes.

Your notes don't need to be migrated. All three apps work on the same files in your Nextcloud, and the tags stay in the note folder's `notes.sqlite`.

## Which clients use which app

The clients choose the server app by the capabilities of the server:

| Client                                   | With the `qownnotes` app                         | Without it                               |
| ---------------------------------------- | ------------------------------------------------ | ---------------------------------------- |
| QOwnNotes Desktop with QOwnNotes support | `/apps/qownnotes/` for versions and trash        | `/apps/qownnotesapi/`                    |
| QOwnNotes Android with QOwnNotes support | `/apps/qownnotes/` for notes, versions and trash | `/apps/notes/` and `/apps/qownnotesapi/` |
| Older QOwnNotes Desktop and Android      | not used                                         | `/apps/notes/` and `/apps/qownnotesapi/` |
| Nextcloud Notes clients                  | not used, they keep using the Notes app          | `/apps/notes/`                           |

The new app doesn't publish the `notes` capability, so it never gets in the way of the Notes app and its clients.

## For administrators

1. Install **QOwnNotes** from the app store (category "Office"), or with `occ app:install qownnotes`.
2. Keep the **Notes** and **QOwnNotesAPI** apps installed until all users have updated their QOwnNotes clients. All three apps can be installed at the same time; they share no database tables or routes.
3. Optional: if you only want to provide the sync APIs, for example because your users use the Nextcloud Notes web interface, disable the web interface in the admin settings (section "QOwnNotes") or with `occ config:app:set qownnotes ui_enabled --value=no`.
4. Optional: install the PHP extension `pdo_sqlite` if it is missing. It is needed for tags; the admin settings show a hint if it is not available.
5. Once all users have updated their clients:
   - uninstall **QOwnNotesAPI**,
   - uninstall **Notes** if it was only installed for QOwnNotes Android.

QOwnNotesAPI stays maintained for a transition period, and it stays the only option for ownCloud servers.

## For users

- Update QOwnNotes Desktop and QOwnNotes Android. The settings of QOwnNotes Desktop show which server app was detected.
- The note folder of the Nextcloud Notes app is taken over: if you never set a note folder in the QOwnNotes app, it uses the folder of the Notes app, or `Notes`. QOwnNotes Android can change it in its settings, and the web interface in "QOwnNotes settings".
- Favorites are the favorites of the Files app, so they are shared with the Notes app.
- After the switch, QOwnNotes Android syncs all notes once, because the note list of the new app is new to it.

### Differences to the Nextcloud Notes app

The QOwnNotes app treats notes like QOwnNotes Desktop does, so QOwnNotes Android may show slightly different notes than with the Notes app:

- Notes in the folders `media`, `attachments` and `trash` and in hidden folders (or folders matching your "ignored subfolders" setting) are not shown.
- New and renamed notes are numbered like in QOwnNotes Desktop (`Note 1.md`) instead of `Note (2).md`.
- Images and attachments uploaded by QOwnNotes Android are stored in the `media` and `attachments` folders of the note folder, like in QOwnNotes Desktop, instead of hidden `.attachments.<id>` folders. Existing links keep working.
- The file suffix setting of the Notes app is not taken over; new notes are `.md` files unless you change it.

## For client developers

To support the app, clients resolve the API base from the capabilities:

1. Read `/ocs/v1.php/cloud/capabilities`. If `qownnotes` exists and `qownnotes.notes_api_version` contains a supported version (1.2 or newer), use `qownnotes.api_base` for both the Notes API and the QOwnNotes API, including attachment URLs.
2. Otherwise fall back to `/index.php/apps/notes/api/v1/` (requires the `notes` capability) and `/index.php/apps/qownnotesapi/api/v1/`.
3. Store the resolved base per account and resolve it again when the capabilities are refreshed. A changed base should trigger a full sync.
4. Optional: if `qownnotes_api_version` contains `1.1` and `tags.available` is true, sync tags with the [tags API](api/qownnotes-api.md#tags) instead of downloading and uploading `notes.sqlite`, and send `X-QOwnNotes-Relink-Tags: 1` with renames and moves instead of relinking the tags locally.

See the [API documentation](api/README.md) for the details.
