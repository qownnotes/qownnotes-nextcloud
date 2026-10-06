# NixOS VM test script; the "test_version" calls are appended by basic.nix
# pyright: reportUndefinedVariable=false
import base64
import json
import shlex
from urllib.parse import quote

BASE_URL = "http://localhost"
APP_URL = f"{BASE_URL}/index.php/apps/qownnotes"
API_URL = f"{APP_URL}/api/v1"
AUTH = "admin:adminpass"


def occ(node, command):
    return node.succeed(f"sudo -u nextcloud nextcloud-occ {command}")


def http(node, method, url, data=None, headers=None, expect=None, form=None):
    """Runs an HTTP request and returns (status, headers, body)"""
    command = f"curl -sS -u {AUTH} -X {method} -D /tmp/headers -o /tmp/body -w '%{{http_code}}'"
    for name, value in (headers or {}).items():
        command += " -H " + shlex.quote(f"{name}: {value}")
    for name, value in (form or {}).items():
        command += " -F " + shlex.quote(f"{name}={value}")
    if data is not None:
        command += " --data-binary @/tmp/request"
        if isinstance(data, str):
            data = data.encode()
        node.succeed(f"echo {base64.b64encode(data).decode()} | base64 -d > /tmp/request")
    command += " " + shlex.quote(url)
    status = int(node.succeed(command).strip())
    raw_headers = node.succeed("cat /tmp/headers")
    body = node.succeed("cat /tmp/body")
    response_headers = {}
    for line in raw_headers.splitlines()[1:]:
        if ":" in line:
            name, value = line.split(":", 1)
            response_headers[name.strip().lower()] = value.strip()
    if expect is not None:
        assert status == expect, f"{method} {url}: expected {expect}, got {status}: {body}"
    return status, response_headers, body


def http_json(node, method, url, payload=None, headers=None, expect=200):
    all_headers = {"Accept": "application/json", "OCS-APIRequest": "true"}
    if payload is not None:
        all_headers["Content-Type"] = "application/json"
    all_headers.update(headers or {})
    status, response_headers, body = http(
        node, method, url, None if payload is None else json.dumps(payload), all_headers, expect
    )
    return json.loads(body) if body.strip() else None, response_headers, status


def dav(node, method, path, data=None, expect=None, headers=None):
    url = f"{BASE_URL}/remote.php/dav/files/admin/{quote(path)}"
    status, response_headers, body = http(node, method, url, data, headers)
    if expect is not None:
        assert status == expect, f"WebDAV {method} {path}: expected {expect}, got {status}: {body}"
    return status, response_headers, body


def dav_exists(node, path):
    return dav(node, "PROPFIND", path, headers={"Depth": "0"})[0] == 207


def api(node, method, path, payload=None, headers=None, expect=200):
    return http_json(node, method, f"{API_URL}/{path}", payload, headers, expect)


def capabilities(node):
    data, _, _ = http_json(node, "GET", f"{BASE_URL}/ocs/v2.php/cloud/capabilities")
    return data["ocs"]["data"]["capabilities"]


def test_app_basics(node, label):
    occ(node, "app:list --enabled | grep -i 'qownnotes:'")
    occ(node, "status | grep -i 'version:'")

    caps = capabilities(node)
    assert "qownnotes" in caps, "The qownnotes capability needs to be published"
    assert "notes" not in caps, "The notes capability belongs to the Nextcloud Notes app"
    qownnotes = caps["qownnotes"]
    assert qownnotes["notes_api_version"] == ["1.4"]
    assert qownnotes["api_base"] == "/index.php/apps/qownnotes/api/v1/"
    assert qownnotes["ui_enabled"] is True
    assert qownnotes["notes_path"] == "Notes"
    assert qownnotes["tags"]["available"] is True


def test_api_only_mode(node, label):
    http(node, "GET", f"{APP_URL}/", expect=200)

    occ(node, "config:app:set qownnotes ui_enabled --value=no")
    http(node, "GET", f"{APP_URL}/", expect=404)
    http(node, "GET", f"{APP_URL}/note/1", expect=404)
    assert capabilities(node)["qownnotes"]["ui_enabled"] is False

    occ(node, "config:app:set qownnotes ui_enabled --value=yes")
    http(node, "GET", f"{APP_URL}/", expect=200)
    assert capabilities(node)["qownnotes"]["ui_enabled"] is True


def test_notes_api_crud(node, label):
    settings, headers, _ = api(node, "GET", "settings")
    assert settings["notesPath"] == "Notes"
    assert settings["fileSuffix"] == ".md"
    assert headers["x-notes-api-versions"] == "1.4"

    first, _, _ = api(node, "POST", "notes", {"title": "First note", "content": "# First note\n\nHello"})
    assert first["title"] == "First note"
    assert first["category"] == ""
    assert first["favorite"] is False
    assert first["readonly"] is False
    assert first["etag"]
    assert dav(node, "GET", "Notes/First note.md", expect=200)[2] == "# First note\n\nHello"

    # Same title: numbered like QOwnNotes Desktop
    duplicate, _, _ = api(node, "POST", "notes", {"title": "first note", "content": "dup"})
    assert duplicate["title"] == "first note 1"

    # Without title: derived from the first line of the content
    derived, _, _ = api(node, "POST", "notes", {"content": "# Derived title\n\nText"})
    assert derived["title"] == "Derived title"

    # Categories are subfolders, created on demand and sanitized
    nested, _, _ = api(node, "POST", "notes", {"title": "Nested", "category": "Work/Project", "content": "nested", "favorite": True, "modified": 1700000000})
    assert nested["category"] == "Work/Project"
    assert nested["favorite"] is True
    assert nested["modified"] == 1700000000
    assert dav_exists(node, "Notes/Work/Project/Nested.md")

    # Internal and ignored folders can't be used as categories
    api(node, "POST", "notes", {"title": "x", "category": "media"}, expect=400)
    api(node, "POST", "notes", {"title": "x", "category": "Work/.hidden"}, expect=400)

    note, headers, _ = api(node, "GET", f"notes/{first['id']}")
    assert note["content"] == "# First note\n\nHello"
    assert headers["etag"].strip('"') == first["etag"]
    api(node, "GET", f"notes/{first['id']}", headers={"If-None-Match": f"\"{first['etag']}\""}, expect=304)
    api(node, "GET", "notes/999999", expect=404)

    # Lost updates are prevented with If-Match
    current, _, _ = api(node, "PUT", f"notes/{first['id']}", {"content": "conflict"}, headers={"If-Match": '"outdated"'}, expect=412)
    assert current["content"] == "# First note\n\nHello"
    updated, _, _ = api(node, "PUT", f"notes/{first['id']}", {"content": "# First note\n\nUpdated"}, headers={"If-Match": f"\"{first['etag']}\""})
    assert updated["content"] == "# First note\n\nUpdated"
    assert updated["etag"] != first["etag"]

    # Renaming and moving renames and moves the file
    renamed, _, _ = api(node, "PUT", f"notes/{first['id']}", {"title": "Renamed: note", "category": "Work"})
    assert renamed["title"] == "Renamed note"
    assert renamed["category"] == "Work"
    assert renamed["id"] == first["id"]
    assert dav_exists(node, "Notes/Work/Renamed note.md")
    assert not dav_exists(node, "Notes/First note.md")

    favorite, _, _ = api(node, "PUT", f"notes/{first['id']}", {"favorite": True})
    assert favorite["favorite"] is True
    assert favorite["content"] == "# First note\n\nUpdated"

    api(node, "DELETE", f"notes/{duplicate['id']}")
    api(node, "GET", f"notes/{duplicate['id']}", expect=404)
    assert not dav_exists(node, "Notes/first note 1.md")


def test_notes_api_listing(node, label):
    # Notes created by other clients via WebDAV are found; ignored folders and non-note files are not
    dav(node, "MKCOL", "Notes/Other", expect=201)
    dav(node, "MKCOL", "Notes/.hidden", expect=201)
    dav(node, "MKCOL", "Notes/media", expect=201)
    dav(node, "PUT", "Notes/Other/From WebDAV.md", "webdav note", expect=201)
    dav(node, "PUT", "Notes/Other/Text file.txt", "text note", expect=201)
    dav(node, "PUT", "Notes/.hidden/Hidden.md", "hidden", expect=201)
    dav(node, "PUT", "Notes/media/Media.md", "media", expect=201)
    dav(node, "PUT", "Notes/image.png", "not a note", expect=201)

    notes, headers, _ = api(node, "GET", "notes")
    titles = {(n["category"], n["title"]) for n in notes}
    assert ("Other", "From WebDAV") in titles
    assert ("Other", "Text file") in titles
    assert ("Work", "Renamed note") in titles
    assert ("Work/Project", "Nested") in titles
    assert all(n["title"] not in ("Hidden", "Media", "image") for n in notes)
    assert headers["x-notes-api-versions"] == "1.4"
    assert headers["etag"]
    assert headers["last-modified"]

    api(node, "GET", "notes", headers={"If-None-Match": headers["etag"]}, expect=304)

    by_category, _, _ = api(node, "GET", "notes?category=Other")
    assert {n["title"] for n in by_category} == {"From WebDAV", "Text file"}

    without_content, _, _ = api(node, "GET", "notes?exclude=content,etag")
    assert all("content" not in n and "etag" not in n and "title" in n for n in without_content)

    # pruneBefore in the future: all notes are only sent with their ID
    pruned, _, _ = api(node, "GET", "notes?pruneBefore=4000000000")
    assert all(list(n.keys()) == ["id"] for n in pruned)
    assert {n["id"] for n in pruned} == {n["id"] for n in notes}

    # Chunked listing returns every note exactly once in full
    seen = []
    cursor = None
    for _ in range(len(notes) + 2):
        query = "notes?chunkSize=2" + (f"&chunkCursor={cursor}" if cursor else "")
        chunk, chunk_headers, _ = api(node, "GET", query)
        seen += [n["id"] for n in chunk if "title" in n]
        cursor = chunk_headers.get("x-notes-chunk-cursor")
        if not cursor:
            assert {n["id"] for n in chunk} == {n["id"] for n in notes}
            break
        assert len([n for n in chunk if "title" in n]) == 2
        assert int(chunk_headers["x-notes-chunk-pending"]) > 0
    assert sorted(seen) == sorted(n["id"] for n in notes)

    api(node, "GET", "notes?chunkCursor=invalid", expect=400)


def test_notes_api_attachments(node, label):
    notes, _, _ = api(node, "GET", "notes?category=Work/Project")
    note_id = notes[0]["id"]

    png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
    node.succeed(f"echo {base64.b64encode(png).decode()} | base64 -d > /tmp/image.png")
    status, _, body = http(node, "POST", f"{API_URL}/attachment/{note_id}", form={"file": "@/tmp/image.png"}, expect=200)
    link = json.loads(body)["filename"]
    assert link == "../../media/image.png", link
    assert dav_exists(node, "Notes/media/image.png")

    node.succeed("echo 'pdf' > /tmp/doc.pdf")
    _, _, body = http(node, "POST", f"{API_URL}/attachment/{note_id}", form={"file": "@/tmp/doc.pdf"}, expect=200)
    assert json.loads(body)["filename"] == "../../attachments/doc.pdf"

    # Attachments are resolved relative to the note, also on the v1.4 path used by QOwnNotes Android
    status, headers, body = http(node, "GET", f"{BASE_URL}/index.php/apps/qownnotes/api/v1.4/attachment/{note_id}?path=../../media/image.png", expect=200)
    assert headers["content-type"].startswith("image/png")
    http(node, "GET", f"{API_URL}/attachment/{note_id}?path=../../../First%20note.md", expect=404)
    http(node, "GET", f"{API_URL}/attachment/{note_id}?path=../../../../../etc/passwd", expect=404)

    # Only media files and attachments can be deleted
    http(node, "DELETE", f"{API_URL}/attachment/{note_id}?path=../Renamed%20note.md", expect=403)
    http(node, "DELETE", f"{API_URL}/attachment/{note_id}?path=../../media/image.png", expect=200)
    assert not dav_exists(node, "Notes/media/image.png")


def test_notes_api_settings(node, label):
    settings, _, _ = api(node, "PUT", "settings", {"notesPath": "/Other Notes/../QOwnNotes/", "fileSuffix": "txt"})
    assert settings["notesPath"] == "QOwnNotes"
    assert settings["fileSuffix"] == ".txt"
    assert dav_exists(node, "QOwnNotes")

    note, _, _ = api(node, "POST", "notes", {"title": "Text note", "content": "text"})
    assert dav_exists(node, "QOwnNotes/Text note.txt")
    notes, _, _ = api(node, "GET", "notes")
    assert [n["id"] for n in notes] == [note["id"]]

    settings, _, _ = api(node, "PUT", "settings", {"notesPath": "Notes", "fileSuffix": ".md"})
    assert settings["notesPath"] == "Notes"
    assert capabilities(node)["qownnotes"]["notes_path"] == "Notes"

    status, headers, _ = http(node, "OPTIONS", f"{API_URL}/notes", headers={"Origin": "https://example.com", "Access-Control-Request-Method": "PUT"})
    assert status == 200, status
    assert "PUT" in headers.get("access-control-allow-methods", "")


def test_version(node, label, pkg_version):
    print(f"Testing Nextcloud {label} ({pkg_version})")
    # Run one Nextcloud version at a time to keep memory usage low
    node.start()
    node.wait_for_unit("phpfpm-nextcloud.service")
    node.wait_for_unit("nginx.service")
    node.succeed("curl -fsSL http://localhost/status.php | grep 'installed' | grep 'true'")

    test_app_basics(node, label)
    test_api_only_mode(node, label)
    test_notes_api_crud(node, label)
    test_notes_api_listing(node, label)
    test_notes_api_attachments(node, label)
    test_notes_api_settings(node, label)

    node.shutdown()
