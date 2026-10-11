#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Validate local release inputs without credentials or publishing anything."""

import argparse
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def check_release(root: Path, built: bool = False) -> tuple[str, str]:
    info = ET.parse(root / "appinfo/info.xml").getroot()
    version = info.findtext("version", "")
    if info.findtext("id") != "qownnotes":
        raise ValueError("appinfo/info.xml must use app ID qownnotes")
    if not re.fullmatch(r"[0-9]{2}\.(?:[1-9]|1[0-2])\.(?:0|[1-9][0-9]*)", version):
        raise ValueError("appinfo/info.xml must contain a CalVer YY.M.patch version")
    for name in ("package.json", "package-lock.json"):
        package = json.loads((root / name).read_text())
        if package.get("version") != version:
            raise ValueError(f"{name} version differs from appinfo/info.xml")
        if (
            name == "package-lock.json"
            and package.get("packages", {}).get("", {}).get("version") != version
        ):
            raise ValueError(
                "package-lock.json root package version differs from appinfo/info.xml"
            )

    changelog = (root / "CHANGELOG.md").read_text()
    sections = list(re.finditer(r"^## (.+)$", changelog, re.MULTILINE))
    matching = [section for section in sections if section.group(1) == version]
    if len(matching) != 1:
        raise ValueError(f"CHANGELOG.md must contain exactly one section for {version}")
    notes = changelog[matching[0].end() :]
    notes = re.split(r"^## ", notes, maxsplit=1, flags=re.MULTILINE)[0].strip()
    if not notes:
        raise ValueError(f"CHANGELOG.md release notes for {version} are empty")

    entries = []
    for line in (root / "release-files.txt").read_text().splitlines():
        if not line or line.startswith("#"):
            continue
        # Both packaging implementations expect literal top-level paths, not globs.
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", line) or line in entries:
            raise ValueError(f"Invalid or duplicate release-files.txt entry: {line!r}")
        entries.append(line)
        path = root / line
        if line == "js" and not built:
            continue
        if not path.exists():
            raise ValueError(f"Missing release input: {line}")
        if path.is_symlink() or (
            path.is_dir() and any(p.is_symlink() for p in path.rglob("*"))
        ):
            raise ValueError(f"Symlinks are not allowed in release input: {line}")
    required = {
        "appinfo",
        "lib",
        "templates",
        "js",
        "img",
        "COPYING",
        "README.md",
        "CHANGELOG.md",
    }
    if missing := required - set(entries):
        raise ValueError(
            f"release-files.txt is missing required inputs: {', '.join(sorted(missing))}"
        )
    if built:
        for entry in ("qownnotes-main.mjs", "qownnotes-files.mjs"):
            if not (root / "js" / entry).is_file():
                raise ValueError(
                    f"Missing frontend entry point js/{entry}: run npm run build first"
                )

    screenshot_base = (
        "https://raw.githubusercontent.com/qownnotes/qownnotes-nextcloud/main/"
    )
    for screenshot in info.findall("screenshot"):
        url = screenshot.text or ""
        if not url.startswith(screenshot_base):
            raise ValueError(f"Screenshot must reference this repository: {url}")
        path = Path(url[len(screenshot_base) :])
        if path.is_absolute() or ".." in path.parts or not (root / path).is_file():
            raise ValueError(f"Missing or invalid app store screenshot: {path}")
    return version, notes + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--root", type=Path, default=Path(__file__).resolve().parent.parent
    )
    parser.add_argument(
        "--built", action="store_true", help="also require the built frontend"
    )
    parser.add_argument(
        "--notes-output", type=Path, help="write the validated release notes"
    )
    args = parser.parse_args()
    try:
        version, notes = check_release(args.root, args.built)
        if args.notes_output:
            args.notes_output.write_text(notes)
    except (OSError, ValueError, ET.ParseError) as error:
        print(f"Release check failed: {error}", file=sys.stderr)
        return 1
    print(version)
    return 0


if __name__ == "__main__":
    sys.exit(main())
