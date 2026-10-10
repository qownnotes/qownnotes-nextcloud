#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Capture a real Desktop schema, then seed explicitly synthetic test variants."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import time


def validate(path):
    with sqlite3.connect(path) as db:
        assert db.execute("PRAGMA quick_check").fetchone() == ("ok",)
        assert db.execute("SELECT value FROM appData WHERE name='database_version'").fetchone() == ("16",)
        assert db.execute("SELECT count(*) FROM tag").fetchone() == (0,)
    assert path.read_bytes()[18:20] == b"\x01\x01"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--desktop", default="QOwnNotes", help="Pinned Desktop executable")
    parser.add_argument("--expected-version", default="26.10.2")
    parser.add_argument("--output", type=Path, default=Path(__file__).parent)
    args = parser.parse_args()
    executable = Path(shutil.which(args.desktop) or args.desktop).resolve(strict=True)
    version = subprocess.check_output([str(executable), "--version"], text=True).strip()
    if version != f"QOwnNotes {args.expected_version}":
        parser.error(f"Expected QOwnNotes {args.expected_version}, got {version}")
    args.output.mkdir(parents=True, exist_ok=True)

    # Never use the operator's settings, note folders, or running GUI instance.
    with tempfile.TemporaryDirectory(prefix="qownnotes-fixture-") as directory:
        root = Path(directory)
        notes = root / "Notes"
        notes.mkdir()
        (notes / "Fixture.md").write_text("# Fixture\n")
        config = root / "config" / "PBE"
        config.mkdir(parents=True)
        (config / "QOwnNotes-fixture.conf").write_text(
            f"[General]\nnotesPath={notes}\ndisableAutomaticUpdateDialog=true\n"
            "allowOnlyOneAppInstance=false\nshowSystemTray=false\n"
            "[webSocketServerService]\nport=0\n"
        )
        env = dict(os.environ, HOME=str(root), XDG_CONFIG_HOME=str(root / "config"),
                   XDG_DATA_HOME=str(root / "data"), XDG_CACHE_HOME=str(root / "cache"),
                   QT_QPA_PLATFORM="offscreen", DBUS_SESSION_BUS_ADDRESS="unix:path=/nonexistent")
        database = notes / "notes.sqlite"
        with (root / "desktop.log").open("wb") as log:
            process = subprocess.Popen(
                [str(executable), "--session", "fixture", "--allow-multiple-instances", "--action", "action_Quit"],
                env=env, stdout=log, stderr=subprocess.STDOUT,
            )
            try:
                deadline = time.monotonic() + 60
                previous = None
                while time.monotonic() < deadline:
                    if database.exists():
                        try:
                            validate(database)
                            digest = hashlib.sha256(database.read_bytes()).hexdigest()
                            if digest == previous:
                                break
                            previous = digest
                        except (sqlite3.DatabaseError, AssertionError):
                            previous = None
                    if process.poll() is not None:
                        raise RuntimeError(f"Desktop exited before creating a stable database: {process.returncode}")
                    time.sleep(1)
                else:
                    raise RuntimeError("Desktop did not create a valid, stable schema-16 database within 60 seconds")
            finally:
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
            validate(database)
            assert not Path(str(database) + "-journal").exists()
            shutil.copyfile(database, args.output / "desktop-16-empty.sqlite")

    populated = args.output / "desktop-16-seeded.sqlite"
    shutil.copyfile(args.output / "desktop-16-empty.sqlite", populated)
    with sqlite3.connect(populated) as db:
        db.executemany(
            "INSERT INTO tag (id,name,parent_id,color,dark_color,created,updated) VALUES (?,?,?,?,?,'2026-01-01 00:00:00','2026-01-01 00:00:00')",
            [(1, "Work", 0, "#ff8800", "#ffaa33"), (2, "Project", 1, None, None), (3, "Home", 0, None, None)],
        )
        db.executemany(
            "INSERT INTO noteTagLink (tag_id,note_file_name,note_sub_folder_path,created,stale_date) VALUES (?,?,?,'2026-01-01 00:00:00',?)",
            [(2, "Meeting.md", "Work/Notes", None), (3, "Groceries.txt", "", None), (1, "Missing.md", "", "2026-01-01 00:00:00")],
        )
        db.execute("INSERT INTO trashItem (file_name,file_size,created) VALUES ('Deleted.md',42,'2026-01-01 00:00:00')")

    wal = args.output / "synthetic-wal.sqlite"
    shutil.copyfile(populated, wal)
    with sqlite3.connect(wal) as db:
        assert db.execute("PRAGMA journal_mode=WAL").fetchone() == ("wal",)
    (args.output / "synthetic-corrupt.sqlite").write_bytes(b"not an SQLite database\n")
    manifest = {
        "desktopVersion": args.expected_version,
        "desktopExecutable": str(executable),
        "desktopExecutableSha256": hashlib.sha256(executable.read_bytes()).hexdigest(),
        "fixtures": {path.name: hashlib.sha256(path.read_bytes()).hexdigest()
                     for path in sorted(args.output.glob("*.sqlite"))},
    }
    (args.output / "provenance.json").write_text(json.dumps(manifest, indent=2) + "\n")
    for name in [*manifest["fixtures"], "provenance.json"]:
        (args.output / (name + ".license")).write_text(
            "SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>\n"
            "SPDX-License-Identifier: AGPL-3.0-or-later\n"
        )


if __name__ == "__main__":
    main()
