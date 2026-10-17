# QOwnNotes for Nextcloud Changelog

## 26.10.2

- Notes whose title starts with an emoji, like `# 🛒 Shopping`, show the emoji as icon in the note list instead of
  the note icon, like in QOwnNotes Desktop; the emoji is taken from the headline of the note, so it also works if
  QOwnNotes Desktop removed it from the file name. Sync conflict copies like `TODO (conflicted copy 2026-07-10 070952)`
  are marked with ⚠️. The emoji icons can be turned off in the menu of the note list

## 26.10.1

- Code blocks are syntax highlighted in the editor and the preview, with colors for light and dark themes;
  in the editor, code blocks are shown in a monospace font on a background like in the preview
- Links to attachments like `[Audio recording](attachments/audio.m4a)` or `[prompt.md](attachments/prompt.md)`
  open in a new browser tab instead of being downloaded, so audio, video, PDF and text files can be viewed right away
  (`GET attachment/{noteId}?path=…&inline=1` of the Notes API)
- The editor and the preview scroll together when they are shown side by side, like in QOwnNotes Desktop;
  the source lines of the rendered blocks keep headings, code blocks and images aligned, and switching between
  the editor and the preview keeps the scroll position
- Polished search panel of the editor in the Nextcloud style: one compact row with icon buttons, toggles for
  case, whole words and regular expressions, the number of matches ("2 of 60", "No results"), and a collapsible
  replace row that is hidden for read-only notes
- Links to other notes like `[TODO](TODO.md#todo)` can be clicked in the preview and Ctrl+clicked (Cmd+click on macOS)
  in the editor, like in QOwnNotes Desktop; Ctrl+Space follows the link at the cursor. The linked note opens relative
  to the subfolder of the current note, and the fragment jumps to its heading (URL-encoded heading text or
  `#heading-anchor`), also for headings of the same note. Other files and web pages open in a new browser tab
- Note links in angle brackets like `<Journal.md>` or `<Work/My note.md>` are rendered as links in the preview and
  highlighted as links in the editor, like in QOwnNotes Desktop, so they can be followed like other note links;
  `<Journal.md#Heading>` also jumps to the heading
- The selected text in the editor is clearly visible again, also in the current line, in code blocks and in inline
  code, because the selection is shown in a stronger color and the line backgrounds no longer cover it
- Trash listings can skip the contents of the deleted notes with `content=0` (`GET note/trashed` and `GET trash`
  of the QOwnNotes API), so large trash folders are listed quickly
- Users who only sync with QOwnNotes Desktop or Android can hide QOwnNotes from their app navigation in the settings
  of the web interface (setting `hideNavigation`); the web interface stays available at `/apps/qownnotes/`
- The endpoints of QOwnNotes API 1.0 (`note/app_info`, `note/versions`, `note/trashed`, `note/restore_trashed`) also
  send the headers `X-Notes-API-Versions` and `X-QOwnNotes-API-Versions`, like all other API responses

## 26.10.0

- Initial development version
  - App skeleton with capabilities (published as `qownnotes`)
  - API-only mode: the web interface can be disabled in the admin settings or with
    `occ config:app:set qownnotes ui_enabled --value=no`
  - Nextcloud Notes API v1.4 compatible note API for QOwnNotes Android under
    `/index.php/apps/qownnotes/api/v1/` (notes, settings, attachments, chunked listing, ETags)
  - Note responses include `internalPath` for Android's version history requests; client request
    contracts for Android and Desktop are checked in the multi-version VM test
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
  - Optional live Markdown preview beside the editor, with a browser-local setting and stacked panes on narrow screens
  - Pressing Enter before a checkbox item or at the start of its text inserts a new unchecked item above it
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
  - Changes of `notes.sqlite` within the same second always get a new ETag, so other clients notice them;
    the modification time keeps increasing, so quick successive changes never bring back an earlier ETag
    and its cached tags
  - `just screenshots` creates the app store and README screenshots with Playwright and a demo user
  - Rejected `notes.sqlite` copies are cleaned up, and the 50 MiB limit is enforced even with stale storage metadata
  - The `notes.sqlite` size limit can be changed in the admin settings or with
    `occ config:app:set qownnotes tag_database_max_size_mib --value=100`
  - Expensive writes outside of the note sync (tags, subfolders, attachments, trash restores) are rate-limited
    to 300 requests per user and endpoint within 5 minutes; the note sync itself is never rate-limited
  - Tag compatibility tests use a real Desktop schema-16 database; VM tests exercise parallel API and WebDAV writes
  - Tag responses respect read-only shares even when permissions change without a new database ETag
  - Tag keys use Desktop and Android base names without suffixes; repeated links do not upload, and changed tag transactions reconcile missing, restored and expired links
  - Release preflight checks versions, release notes, packaging inputs and app store screenshots locally and in CI; signing rejects missing inputs
  - Polished web interface and dialogs for desktop and mobile in light and dark themes: notes selected with
    Ctrl/Shift+click are now highlighted, the editor actions stay beside long titles, the settings dialog keeps
    Save and Cancel visible, the deleted notes dialog stacks its preview on small screens and selects the next
    note after a restore, and long tag names wrap in the tag color dialog
  - The save status ("Unsaved changes", "Saving …") is shown beside the editor actions instead of pushing the editor down
  - Monochrome app icon based on the QOwnNotes icon (note sheet with header bar, cloud and folded corner)
  - Documentation of the APIs (`docs/api/`), the handling of `notes.sqlite` and a migration guide for
    users of the QOwnNotesAPI and Nextcloud Notes apps
