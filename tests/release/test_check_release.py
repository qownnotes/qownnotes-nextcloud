# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/check-release.py"
SPEC = importlib.util.spec_from_file_location("check_release", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ReleaseCheckTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for folder in ("appinfo", "lib", "templates", "js", "img", "docs/screenshots"):
            (self.root / folder).mkdir(parents=True)
        (self.root / "appinfo/info.xml").write_text(
            "<info><id>qownnotes</id><version>26.10.0</version><screenshot>"
            "https://raw.githubusercontent.com/qownnotes/qownnotes-nextcloud/main/docs/screenshots/editor.png"
            "</screenshot></info>"
        )
        for file in (
            "COPYING",
            "README.md",
            "docs/screenshots/editor.png",
            "js/qownnotes-main.mjs",
            "js/qownnotes-files.mjs",
        ):
            (self.root / file).write_text("fixture")
        (self.root / "CHANGELOG.md").write_text(
            "# Changelog\n\n## 26.10.0\n\n- Initial release\n\n## 26.9.0\n\n- Older release\n"
        )
        for name in ("package.json", "package-lock.json"):
            (self.root / name).write_text(
                json.dumps(
                    {"version": "26.10.0", "packages": {"": {"version": "26.10.0"}}}
                )
            )
        (self.root / "release-files.txt").write_text(
            "# Release inputs\nappinfo\nlib\ntemplates\njs\nimg\nCOPYING\nREADME.md\nCHANGELOG.md\n"
        )

    def test_valid_release_and_notes(self):
        self.assertEqual(
            MODULE.check_release(self.root, True), ("26.10.0", "- Initial release\n")
        )

    def test_cli_extracts_notes_for_workflow(self):
        output = self.root / "release-notes.md"
        result = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--root",
                str(self.root),
                "--notes-output",
                str(output),
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "26.10.0\n")
        self.assertEqual(output.read_text(), "- Initial release\n")

    def test_cli_does_not_extract_notes_on_failure(self):
        output = self.root / "release-notes.md"
        (self.root / "COPYING").unlink()
        result = subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--root",
                str(self.root),
                "--notes-output",
                str(output),
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, "")
        self.assertIn("Release check failed", result.stderr)
        self.assertFalse(output.exists())

    def test_version_mismatches(self):
        for name in ("package.json", "package-lock.json"):
            with self.subTest(name=name):
                path = self.root / name
                original = path.read_text()
                path.write_text(original.replace("26.10.0", "26.9.0"))
                with self.assertRaisesRegex(ValueError, "version differs"):
                    MODULE.check_release(self.root)
                path.write_text(original)

    def test_lock_root_version_mismatch(self):
        path = self.root / "package-lock.json"
        package = json.loads(path.read_text())
        package["packages"][""]["version"] = "26.9.0"
        path.write_text(json.dumps(package))
        with self.assertRaisesRegex(ValueError, "root package version differs"):
            MODULE.check_release(self.root)

    def test_invalid_calver(self):
        path = self.root / "appinfo/info.xml"
        path.write_text(path.read_text().replace("26.10.0", "26.13.0"))
        with self.assertRaisesRegex(ValueError, "CalVer"):
            MODULE.check_release(self.root)

    def test_empty_missing_and_duplicate_notes(self):
        for notes in (
            "## 26.10.0",
            "## 26.10.0\n\n",
            "## 26.9.0\n- Other\n",
            "## 26.10.0\n- One\n## 26.10.0\n- Two\n",
        ):
            with self.subTest(notes=notes):
                (self.root / "CHANGELOG.md").write_text(notes)
                with self.assertRaisesRegex(ValueError, "CHANGELOG.md"):
                    MODULE.check_release(self.root)

    def test_missing_release_input(self):
        (self.root / "COPYING").unlink()
        with self.assertRaisesRegex(ValueError, "Missing release input"):
            MODULE.check_release(self.root)

    def test_unsafe_and_duplicate_manifest_entries(self):
        path = self.root / "release-files.txt"
        original = path.read_text()
        for entry in ("../outside", "/etc", "lib/*", "lib", "two words"):
            with self.subTest(entry=entry):
                path.write_text(original + entry + "\n")
                with self.assertRaisesRegex(ValueError, "Invalid or duplicate"):
                    MODULE.check_release(self.root)

    def test_required_manifest_entry(self):
        path = self.root / "release-files.txt"
        path.write_text(path.read_text().replace("lib\n", ""))
        with self.assertRaisesRegex(ValueError, "missing required inputs: lib"):
            MODULE.check_release(self.root)

    def test_symlink_in_runtime_folder(self):
        (self.root / "lib/external.php").symlink_to(self.root / "COPYING")
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            MODULE.check_release(self.root)

    def test_build_is_only_required_when_requested(self):
        (self.root / "js/qownnotes-main.mjs").unlink()
        (self.root / "js/qownnotes-files.mjs").unlink()
        (self.root / "js").rmdir()
        MODULE.check_release(self.root)
        with self.assertRaisesRegex(ValueError, "Missing release input: js"):
            MODULE.check_release(self.root, True)
        (self.root / "js").mkdir()
        with self.assertRaisesRegex(ValueError, "Missing frontend entry point"):
            MODULE.check_release(self.root, True)

    def test_files_integration_bundle_is_required(self):
        (self.root / "js/qownnotes-files.mjs").unlink()
        with self.assertRaisesRegex(ValueError, "js/qownnotes-files.mjs"):
            MODULE.check_release(self.root, True)

    def test_missing_screenshot(self):
        (self.root / "docs/screenshots/editor.png").unlink()
        with self.assertRaisesRegex(ValueError, "screenshot"):
            MODULE.check_release(self.root)


if __name__ == "__main__":
    unittest.main()
