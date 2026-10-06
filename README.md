# QOwnNotes for Nextcloud

[Changelog](CHANGELOG.md) |
[Issues](https://github.com/qownnotes/qownnotes-nextcloud/issues) |
[Planning document](docs/plan.md)

QOwnNotes for Nextcloud lets you view and edit the notes of your [QOwnNotes](https://www.qownnotes.org) note folder in the Nextcloud web interface, including note subfolders and the tags stored in the note folder's `notes.sqlite`.

It also provides the server APIs for the [QOwnNotes desktop app](https://www.qownnotes.org) (note versions and trash) and the [QOwnNotes Android app](https://github.com/qownnotes/qownnotes-android) (a Nextcloud Notes API compatible note API).

Administrators can disable the web interface to only provide the APIs.

> [!WARNING]
> This app is in early development. See the [planning document](docs/plan.md) for the roadmap.

## Installation from the git repository

```bash
git clone https://github.com/qownnotes/qownnotes-nextcloud.git apps/qownnotes
occ app:enable qownnotes
```

## API-only mode

Disable the web interface in the admin settings (section "QOwnNotes"), or with:

```bash
occ config:app:set qownnotes ui_enabled --value=no
```

## Development

The development environment is provided by [devenv](https://devenv.sh) (`direnv allow` or `devenv shell`).

```bash
composer install
just lint-php          # syntax, code style, static analysis and unit tests
just vm-test-version   # NixOS VM test against a single Nextcloud version (default: 34)
just vm-test           # NixOS VM test against all supported Nextcloud versions
```

The VM tests use the git-tracked files, so add new files with `git add` before running them.

## License

AGPL-3.0-or-later, see [COPYING](COPYING).
