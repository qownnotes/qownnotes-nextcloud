# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/generate-translations.py"
SPEC = importlib.util.spec_from_file_location("generate_translations", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class TranslationTest(unittest.TestCase):
    def test_catalogs_are_current_and_cover_all_source_strings(self):
        subprocess.run([sys.executable, str(SCRIPT), "--check"], check=True)

    def test_translations_are_shipped(self):
        self.assertIn("l10n", (ROOT / "release-files.txt").read_text().splitlines())

    def check_invalid_catalog(self, source, message):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "ui.tsv"
            path.write_text(source, encoding="utf-8")
            with self.assertRaisesRegex(ValueError, message):
                MODULE.load_translations(path)

    def test_rejects_missing_placeholder(self):
        source = (ROOT / "translations/ui.tsv").read_text(encoding="utf-8")
        source = source.replace("Datenbankversion: {version}", "Datenbankversion")
        self.check_invalid_catalog(source, "placeholders differ")

    def test_rejects_empty_translation(self):
        source = (ROOT / "translations/ui.tsv").read_text(encoding="utf-8")
        source = source.replace("\tAktionen\t", "\t\t", 1)
        self.check_invalid_catalog(source, "empty translation")

    def test_rejects_wrong_plural_count(self):
        source = (ROOT / "translations/ui.tsv").read_text(encoding="utf-8")
        source = source.replace("{count} Notiz ausgewählt|", "", 1)
        self.check_invalid_catalog(source, "wrong plural form count")

    def test_rejects_duplicate_strings(self):
        source = (ROOT / "translations/ui.tsv").read_text(encoding="utf-8")
        source += source.splitlines()[1] + "\n"
        self.check_invalid_catalog(source, "duplicate source string")

    def test_extracts_escaped_strings_and_plurals(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "src").mkdir()
            (root / "templates").mkdir()
            (root / "src/example.vue").write_text(
                r"t('qownnotes', 'Note \u201C{title}\u201D'); "
                r"t('qownnotes', 'Don\'t'); "
                "n('qownnotes', '{count} note', '{count} notes', count); "
                "t('other-app', 'Not ours');",
                encoding="utf-8",
            )
            (root / "templates/example.php").write_text(
                "$l->t('Admin settings');", encoding="utf-8"
            )
            self.assertEqual(
                MODULE.source_strings(root),
                {
                    "Note “{title}”",
                    "Don't",
                    "_{count} note_::_{count} notes_",
                    "Admin settings",
                },
            )


if __name__ == "__main__":
    unittest.main()
