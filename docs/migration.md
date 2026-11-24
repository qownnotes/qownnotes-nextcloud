# Migration guide

QOwnNotes for Nextcloud (app ID `qownnotes`) replaces two server apps for QOwnNotes users:

- the [QOwnNotesAPI](https://github.com/pbek/qownnotesapi) app (`qownnotesapi`), which QOwnNotes Desktop and Android use for note versions and the trash,
- the [Nextcloud Notes](https://github.com/nextcloud/notes) app (`notes`), which QOwnNotes Android uses to sync notes.

You don't need to export or import your notes. Point QOwnNotes at the folder that already holds them in Nextcloud. The files stay where they are, and QOwnNotes tags stay in that folder's `notes.sqlite`.

## Which clients use which app

Supported QOwnNotes clients automatically detect QOwnNotes for Nextcloud. Desktop uses it for versions and trash; syncing the Desktop note folder still works as before, for example through the Nextcloud desktop sync client.

| Client                                                                                                                            | When QOwnNotes for Nextcloud is available                          | Otherwise                                                                      |
| --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| QOwnNotes Desktop [26.10.5](https://github.com/pbek/QOwnNotes/releases/tag/v26.10.5) or later                                     | QOwnNotes for Nextcloud for versions and trash                     | QOwnNotesAPI                                                                   |
| QOwnNotes Android [1.4.0](https://github.com/qownnotes/qownnotes-android/releases/tag/v1.4.0) or later, including CalVer releases | QOwnNotes for Nextcloud for notes, attachments, versions and trash | Nextcloud Notes for notes and attachments; QOwnNotesAPI for versions and trash |
| Older QOwnNotes Desktop                                                                                                           | QOwnNotesAPI                                                       | QOwnNotesAPI                                                                   |
| Older QOwnNotes Android                                                                                                           | Nextcloud Notes and QOwnNotesAPI                                   | Nextcloud Notes and QOwnNotesAPI                                               |
| Other Nextcloud Notes clients                                                                                                     | Nextcloud Notes                                                    | Nextcloud Notes                                                                |

QOwnNotes for Nextcloud advertises the `qownnotes` capability, not `notes`, so Nextcloud Notes clients continue to use the Notes app.

## For administrators

1. Install **QOwnNotes** from the [Nextcloud App Store](https://apps.nextcloud.com/apps/qownnotes) (**Office & text**), or run `occ app:install qownnotes`.
2. Keep **Notes** and **QOwnNotesAPI** installed while users update their clients and verify the switch. All three apps can be installed at the same time.
3. Optional: if you only want to provide the sync APIs, for example because your users use the Nextcloud Notes web interface, disable the web interface in the admin settings (section "QOwnNotes") or with `occ config:app:set qownnotes ui_enabled --value=no`.
4. Optional: install the PHP extension `pdo_sqlite` if it is missing. It is needed for tags; the admin settings show a hint if it is not available.
5. Once users have confirmed that their notes, versions and trash are accessible through QOwnNotes for Nextcloud:
   - uninstall **QOwnNotesAPI** if no clients still rely on it,
   - uninstall **Notes** only if nobody uses its web interface or a client that needs it.

QOwnNotesAPI is deprecated on Nextcloud. QOwnNotes for Nextcloud does not support ownCloud; keep QOwnNotesAPI on ownCloud servers.

## For users

- Update QOwnNotes Desktop and Android to supported versions. The connection test in Desktop's settings shows which server app was detected.
- On first use, QOwnNotes for Nextcloud adopts the folder configured in Nextcloud Notes, including localized folders such as `Notizen` (fixed in server app **26.11.1**). If Nextcloud Notes has no folder configured, it defaults to `Notes`. Once QOwnNotes has saved a folder, changing it in Nextcloud Notes does not change it in QOwnNotes.
- Favorites are the favorites of the Files app, so they are shared with the Notes app.
- When Android switches server apps, it refreshes the full note list once without discarding pending edits.

### If your notes are missing

If QOwnNotes previously selected the wrong folder, updating the app won't change that saved setting. If the web app or Android shows different notes than Desktop, check which Nextcloud folder Desktop syncs.

In the web app's **QOwnNotes settings**, use the folder picker or choose one of the suggested folders with notes. You can also change the folder in Android's **Note settings**; Android **26.11.2** and later can browse and suggest folders. Android and the QOwnNotes web app share this setting. Choose the same folder that Desktop syncs if you want all three to show the same notes. Changing the setting does not move or merge files.

### Differences to the Nextcloud Notes app

The QOwnNotes app treats notes like QOwnNotes Desktop does, so QOwnNotes Android may show slightly different notes than with the Notes app:

- Notes in `media`, `attachments` and `trash` folders are not shown. Hidden folders are also ignored by default; you can change this in your ignored subfolder settings.
- If a new or renamed note would have the same filename as an existing note, QOwnNotes appends a number like Desktop (`Note 1.md` instead of `Note (2).md`).
- The attachment API stores new images in `media` and other uploads in `attachments` at the root of the note folder. Existing relative links can still open files at their original location within the note folder, including files in `.attachments.<id>` directories.
- The Nextcloud Notes file suffix setting is not copied. QOwnNotes lists `.md` and `.txt` notes by default and creates new notes as `.md`. If existing notes use a different suffix, set it in QOwnNotes to make them visible.

## For client developers

To support the app, clients resolve the API base from the capabilities:

1. Read `/ocs/v1.php/cloud/capabilities`. Use `qownnotes.api_base` on the configured Nextcloud server for the APIs your client supports. Android uses it for both APIs, including attachments, when `qownnotes.notes_api_version` advertises a supported 1.x version (1.2 or later). Desktop uses it for the legacy QOwnNotes API and does not require the Notes API.
2. Without a compatible QOwnNotes for Nextcloud app, fall back to `/index.php/apps/notes/api/v1/` for note sync (requires the `notes` capability) and `/index.php/apps/qownnotesapi/api/v1/` for versions and trash.
3. Keep the resolved base per account and resolve it again when capabilities are refreshed. For note sync, discard the old collection ETag and incremental checkpoint when the base changes, but keep pending edits.
4. Optional: if `qownnotes_api_version` advertises `1.1` and `tags.available` is true, use the [tags API](api/qownnotes-api.md#tags) instead of downloading and uploading `notes.sqlite`. Check `GET tags` for whether the user's database is writable. Clients using this API can send `X-QOwnNotes-Relink-Tags: 1` on renames and moves instead of relinking tags locally.

See the [API documentation](api/README.md) for the details.
