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
  - QOwnNotes API 1.0 compatible with the qownnotesapi app for QOwnNotes Desktop and Android
    (`note/app_info`, `note/versions`, `note/trashed`, `note/restore_trashed`)
  - QOwnNotes API 1.1: note subfolders (`subfolders`) and tags of the note folder database
    `notes.sqlite` (`tags`, `tags/batch`, `tag-links`, `note/{id}/tags`), with safe, ETag-protected
    writes in the QOwnNotes Desktop format
  - Web interface: note list with search, note subfolder tree with folder operations and drag and drop,
    tag tree with filtering (all/any tags, child tags, untagged notes), tag editor, Markdown editor with
    autosave and conflict handling, preview with checkable tasks, favorites and media uploads
  - Notes renamed or moved by the web interface keep their tags, and relative links to media files and
    attachments are adapted to the new subfolder depth (opt-in for API clients with `X-QOwnNotes-Relink-Tags`)
  - Tag management in the web interface: colors for light and dark themes, moving tags by drag and drop,
    and tagging, untagging, favoring or deleting several selected notes at once (Ctrl/Shift+click)
  - Note sidebar with file details and previous versions (with differences and restore), deleted notes
    can be restored with their tags, and the conflict dialog shows the differences
  - QOwnNotes API 1.1: `note/{id}/info`, `note/{id}/versions`, `trash` and `trash/restore`
  - Integrations: "Recent notes" dashboard widget, unified search for notes (including tags, `#tag`),
    link previews and smart picker for notes, and "Open in QOwnNotes" in the Files app; all of them are
    hidden in API-only mode
  - Changes of `notes.sqlite` within the same second always get a new ETag, so other clients notice them
  - Documentation of the APIs (`docs/api/`), the handling of `notes.sqlite` and a migration guide for
    users of the QOwnNotesAPI and Nextcloud Notes apps
