# QOwnNotes for Nextcloud Changelog

## 26.10.0

- Initial development version
  - App skeleton with capabilities (published as `qownnotes`)
  - API-only mode: the web interface can be disabled in the admin settings or with
    `occ config:app:set qownnotes ui_enabled --value=no`
  - Nextcloud Notes API v1.4 compatible note API for QOwnNotes Android under
    `/index.php/apps/qownnotes/api/v1/` (notes, settings, attachments, chunked listing, ETags)
  - Notes follow the QOwnNotes conventions: categories are note subfolders, internal and ignored
    subfolders are skipped, new notes are numbered like in QOwnNotes Desktop, and media files and
    attachments are stored in the `media` and `attachments` folders
