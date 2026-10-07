# AGENTS.md

This file contains information for automated tools and agents working with this codebase.

## Overview

Nextcloud app `qownnotes` (namespace `OCA\QOwnNotes`, PHP in `lib/`). See `docs/plan.md` for the architecture and roadmap.

- Notes are plain files in the user's note folder; tags live in the QOwnNotes note folder database `notes.sqlite`.
- The Notes API (`/api/v1/notes`, `/api/v1/settings`, `/api/v1/attachment`) must stay compatible with the Nextcloud Notes API v1 as used by QOwnNotes Android.
- The legacy QOwnNotes API (`/api/v1/note/{app_info,versions,trashed,restore_trashed}`) must stay byte-compatible with qownnotesapi as used by QOwnNotes Desktop and Android.
- Never publish a `notes` capability, only `qownnotes`.
- Only use public Nextcloud APIs (`OCP\`), plus the interfaces of the files_versions and files_trashbin apps (stubs in `tests/stubs/`).

## Version Number Location

The version number is located in `appinfo/info.xml` within the `<version>` tag (CalVer, e.g. `26.10.0`). Keep `version` in `package.json` in sync; the release workflow checks it.

## Release Files

The release archive (`docker/sign-app.sh`, `just sign-app`) and the app in the VM test only contain the files and folders listed in `release-files.txt`. Add new runtime files or folders there. The release process is described in `docs/release.md`.

## Documentation

API changes must be reflected in `docs/api/` (`notes-api.md`, `qownnotes-api.md`), changes of the `notes.sqlite` handling in `docs/notes-sqlite.md`.

## Checks

- `just lint-php` runs PHP syntax checks, php-cs-fixer, psalm and the unit tests.
- `just vm-test-version` runs the NixOS VM test (`tests/vm/`) against one Nextcloud version. New files must be added to git first.
- `just format` runs all formatters (pre-commit hooks).
