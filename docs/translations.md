# UI translations

The app uses the user's Nextcloud language automatically. App-owned strings in the
web interface, admin settings, Files action, search, dashboard and note references
are translated. Shared Nextcloud components use Nextcloud's own translations.
API responses and note contents are not translated.

## Languages

| Requested locale               | Nextcloud catalog |
| ------------------------------ | ----------------- |
| German (`de_DE`)               | `de`              |
| Simplified Chinese (`zh_CN`)   | `zh_CN`           |
| Russian (`ru_RU`)              | `ru`              |
| Italian (`it_IT`)              | `it`              |
| Brazilian Portuguese (`pt_BR`) | `pt_BR`           |
| Polish (`pl_PL`)               | `pl`              |
| French (`fr_FR`)               | `fr`              |
| Spanish (`es_ES`)              | `es`              |

These are Nextcloud language identifiers, not the separate regional formatting
setting. English is the source language and fallback.

## Updating translations

Edit `translations/ui.tsv` (UTF-8, tab-separated, without CSV quoting). Each row
contains an exact English source string followed by the translations in the
language order given by the header. Keep placeholders such as `{title}` and
`{message}` unchanged. Spaces, including non-breaking spaces, in source strings
must match the code exactly.

Plural keys use Nextcloud's `_singular_::_plural_` format. Separate translated
plural forms with `|`, in gettext plural-index order. The plural rules in
`scripts/generate-translations.py` follow Nextcloud's translation tooling: one
form for Chinese, two for German, four for Russian and Polish, and three for
Italian, Brazilian Portuguese, French and Spanish (including the million form).

Regenerate and verify the runtime catalogs with Python 3 (no extra dependencies):

```sh
python3 scripts/generate-translations.py
python3 scripts/generate-translations.py --check
```

The generator validates source-string coverage, nonempty translations,
placeholders and plural form counts. It produces `l10n/<language>.js` for the
browser and `l10n/<language>.json` for PHP in Nextcloud's standard catalog format.
Commit both the sources and generated catalogs; never edit `l10n/` directly.
Only the generated `l10n/` directory is shipped, via `release-files.txt`.

`just release-check` checks that generated files are current. `npm test` also
checks browser registration, PHP/browser catalog parity and plural selection.
