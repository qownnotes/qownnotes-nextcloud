# Client request contracts

`requests.json` contains synthetic request templates transcribed from the clients, not network recordings. Authentication and the endpoint base are supplied by the VM test; the base is changed to `/index.php/apps/qownnotes/api/v1/`, as planned for client discovery. No client repository is modified.

Sources:

- [QOwnNotes Android](https://github.com/qownnotes/qownnotes-android/tree/4b2e45a1253fa7bd3fdbde9e6642a49cff7e3cf2), `backend-nextcloud/src/main/kotlin/org/qownnotes/mobile/backend/nextcloud/NextcloudBackend.kt`: `NotesApi`, `NoteWriteDto`, `QOwnNotesApi`, `loadVersionsFromApis`, `loadTrashedNotesFromApis`, `restoreTrashedNoteWithApi`.
- [QOwnNotes Desktop](https://github.com/pbek/QOwnNotes/tree/2679a2e450181c57cf864fa1194f2cabe9a23d40), `src/services/cloudservice.cpp`: `startAppVersionTest`, `loadVersions`, `loadTrash`, `restoreTrashedNoteOnServer`. Both clients send a leading slash in the remote path; Android uses the note's `internalPath` and explicitly rejects paths without the slash.

The VM test replays these templates against each supported Nextcloud version, covering custom suffixes, nested note folders, complete Android write DTOs, quoted ETags, rename/move path changes, stale-write conflicts, real file versions, repeated `extensions[]` parameters, and legacy GET trash restoration. Response assertions follow the fields consumed by the clients, including Android's required `internalPath`. Existing VM tests cover pagination, attachments and tag database round trips separately.

Run with `just vm-test-version 34` or `just vm-test`. When a client's request shape changes, review the source at the new commit, update the templates and this provenance, and keep legacy behavior covered until the older clients are no longer supported.
