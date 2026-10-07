# NixOS VM test script; the "test_version" calls are appended by basic.nix
# pyright: reportUndefinedVariable=false
import base64
import html
import json
import re
import shlex
from urllib.parse import quote, urlencode

BASE_URL = "http://localhost"
APP_URL = f"{BASE_URL}/index.php/apps/qownnotes"
API_URL = f"{APP_URL}/api/v1"
AUTH = "admin:adminpass"


def occ(node, command):
    return node.succeed(f"sudo -u nextcloud nextcloud-occ {command}")


# Nextcloud caches app config values in APCu for up to 3 seconds (AppConfig::LOCAL_CACHE_TTL), and occ runs
# in a separate process that can not clear the cache of PHP-FPM
APP_CONFIG_CACHE_TTL = 3


def occ_app_config(node, command):
    """Runs an occ command that changes app config values and waits until the web server sees the change"""
    output = occ(node, command)
    node.sleep(APP_CONFIG_CACHE_TTL + 1)
    return output


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
    url = f"{BASE_URL}/remote.php/dav/files/admin/{quote(path.lstrip('/'))}"
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


def test_web_interface(node, label):
    # The page loads the frontend bundle that was built by Nix
    _, _, body = http(node, "GET", f"{APP_URL}/", expect=200)
    match = re.search(r'src="([^"]*/qownnotes-main\.mjs[^"]*)"', body)
    assert match, "The frontend script is missing in the page"
    script_url = shlex.quote(BASE_URL + html.unescape(match.group(1)))
    status, size, content_type = node.succeed(f"curl -sS -u {AUTH} -o /dev/null -w '%{{http_code}}|%{{size_download}}|%{{content_type}}' {script_url}").split("|", 2)
    assert status == "200" and "javascript" in content_type and int(size) > 1000, (status, content_type, size)

    # Client-side routes are served by the same page
    for path in ["folder", "folder/Work/Project", "note/1"]:
        http(node, "GET", f"{APP_URL}/{path}", expect=200)


def test_api_only_mode(node, label):
    http(node, "GET", f"{APP_URL}/", expect=200)

    occ_app_config(node, "config:app:set qownnotes ui_enabled --value=no")
    http(node, "GET", f"{APP_URL}/", expect=404)
    http(node, "GET", f"{APP_URL}/note/1", expect=404)
    assert capabilities(node)["qownnotes"]["ui_enabled"] is False

    occ_app_config(node, "config:app:set qownnotes ui_enabled --value=yes")
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


def legacy_api(node, endpoint, params=None, method="GET"):
    query = "?" + urlencode(params or {}, doseq=True)
    data, _, _ = http_json(node, method, f"{API_URL}/note/{endpoint}{query}")
    return data


def test_legacy_api(node, label, pkg_version):
    """Ported from the qownnotesapi VM test: the responses must stay compatible for QOwnNotes Desktop and Android"""
    missing_path_info = legacy_api(node, "app_info", {"notes_path": "/Missing"})
    assert missing_path_info["user"] == "admin"
    assert missing_path_info["versions_app"] is True
    assert missing_path_info["trash_app"] is True
    assert missing_path_info["versioning"] is True
    assert missing_path_info["app_version"] == "26.10.0"
    assert missing_path_info["server_version"].startswith(label + ".")
    assert missing_path_info["notes_path_exists"] is False

    dav(node, "MKCOL", "Legacy", expect=201)
    dav(node, "MKCOL", "Legacy/Sub", expect=201)
    dav(node, "MKCOL", "LegacyOther", expect=201)
    notes_path_info = legacy_api(node, "app_info", {"notes_path": "/Legacy"})
    assert notes_path_info["notes_path_exists"] is True

    original_note = "First version of the note\n"
    current_note = "Current version of the note\n"
    dav(node, "PUT", "Legacy/versioned.md", original_note)
    node.succeed("sleep 1.1")
    dav(node, "PUT", "Legacy/versioned.md", current_note)

    versions = legacy_api(node, "versions", {"file_name": "/Legacy/versioned.md"})
    assert versions["file_name"] == "/Legacy/versioned.md"
    assert versions["error_messages"] == []
    assert len(versions["versions"]) >= 1
    assert all(version["data"] != current_note for version in versions["versions"]), "The current version must not be listed"
    assert any(version["data"] == original_note for version in versions["versions"])
    assert all(version["timestamp"] > 0 for version in versions["versions"])
    assert all(version["humanReadableTimestamp"] for version in versions["versions"])
    assert all(version["diffHtml"] for version in versions["versions"])
    assert "<del>Current</del><ins>First</ins>" in versions["versions"][0]["diffHtml"], versions["versions"][0]["diffHtml"]

    missing = legacy_api(node, "versions", {"file_name": "/Legacy/missing.md"})
    assert missing["versions"] == []
    assert missing["error_messages"] == ["Requested file was not found!"]

    dav(node, "PUT", "Legacy/trashed.md", "Trashed Markdown note\n")
    dav(node, "PUT", "Legacy/custom.qnote", "Custom extension note\n")
    dav(node, "PUT", "Legacy/ignored.json", '{"ignored": true}\n')
    dav(node, "PUT", "Legacy/Sub/nested.md", "Nested note\n")
    dav(node, "PUT", "LegacyOther/outside.md", "Note outside requested directory\n")
    for path in ["Legacy/trashed.md", "Legacy/custom.qnote", "Legacy/ignored.json", "Legacy/Sub/nested.md", "LegacyOther/outside.md"]:
        dav(node, "DELETE", path, expect=204)

    trash = legacy_api(node, "trashed", {"dir": "/Legacy/", "extensions[]": ["qnote"]})
    assert trash["directory"] == "Legacy"
    trashed_notes = {note["fileName"]: note for note in trash["notes"]}
    assert set(trashed_notes) == {"trashed.md", "custom.qnote"}, set(trashed_notes)
    assert trashed_notes["trashed.md"]["noteName"] == "trashed"
    assert trashed_notes["trashed.md"]["data"] == "Trashed Markdown note\n"
    assert trashed_notes["custom.qnote"]["data"] == "Custom extension note\n"
    assert all(note["timestamp"] > 0 for note in trash["notes"])
    assert all(note["dateString"] for note in trash["notes"])

    # Notes deleted from subfolders are only included on request
    recursive = legacy_api(node, "trashed", {"dir": "/Legacy/", "recursive": "1"})
    assert {note["fileName"] for note in recursive["notes"]} == {"trashed.md", "nested.md"}

    deleted_note = trashed_notes["trashed.md"]
    restore = legacy_api(node, "restore_trashed", {"file_name": "/Legacy/trashed.md", "timestamp": deleted_note["timestamp"]})
    assert restore["result"] is True
    assert restore["filename"] == "trashed.md"
    assert restore["path"] == f"//trashed.md.d{deleted_note['timestamp']}"
    assert dav(node, "GET", "Legacy/trashed.md")[2] == "Trashed Markdown note\n"

    trash_after_restore = legacy_api(node, "trashed", {"dir": "/Legacy/", "extensions[]": ["qnote"]})
    remaining_names = {note["fileName"] for note in trash_after_restore["notes"]}
    assert "trashed.md" not in remaining_names
    assert "custom.qnote" in remaining_names

    nested = next(n for n in recursive["notes"] if n["fileName"] == "nested.md")
    restore = legacy_api(node, "restore_trashed", {"file_name": "Legacy/Sub/nested.md", "timestamp": nested["timestamp"]}, method="POST")
    assert restore["result"] is True
    assert dav(node, "GET", "Legacy/Sub/nested.md")[2] == "Nested note\n"

    failed = legacy_api(node, "restore_trashed", {"file_name": "/Legacy/unknown.md", "timestamp": 1})
    assert failed["result"] is False

    occ_app_config(node, "app:disable files_versions")
    assert legacy_api(node, "app_info")["versions_app"] is False
    assert legacy_api(node, "versions", {"file_name": "/Legacy/versioned.md"})["error_messages"]
    occ_app_config(node, "app:enable files_versions")


NOTES_SQLITE = "/var/lib/nextcloud/data/admin/files/Notes/notes.sqlite"


def sqlite(node, query):
    """Queries notes.sqlite directly, like QOwnNotes Desktop would read it"""
    return node.succeed(f"sqlite3 -separator '|' {NOTES_SQLITE} {shlex.quote(query)}").strip()


def tag_links(node):
    return sqlite(node, "SELECT t.name, l.note_file_name, l.note_sub_folder_path, l.stale_date IS NOT NULL FROM noteTagLink l JOIN tag t ON t.id = l.tag_id ORDER BY t.name, l.note_file_name").splitlines()


def find_note(node, title):
    notes, _, _ = api(node, "GET", "notes?exclude=content")
    return next(n for n in notes if n["title"] == title)


def test_subfolders(node, label):
    tree, _, _ = api(node, "GET", "subfolders")
    assert tree["path"] == ""
    names = [child["name"] for child in tree["children"]]
    assert "Work" in names and "Other" in names, names
    assert "media" not in names and ".hidden" not in names and "attachments" not in names, names
    work = next(child for child in tree["children"] if child["name"] == "Work")
    assert work["noteCount"] == 1
    assert work["noteCountRecursive"] == 2
    assert [child["path"] for child in work["children"]] == ["Work/Project"]

    created, _, _ = api(node, "POST", "subfolders", {"path": "Empty/Deep"})
    assert created["path"] == "Empty/Deep"
    tree, _, _ = api(node, "GET", "subfolders")
    empty = next(child for child in tree["children"] if child["name"] == "Empty")
    assert empty["noteCountRecursive"] == 0
    assert empty["children"][0]["name"] == "Deep"

    api(node, "POST", "subfolders", {"path": "media"}, expect=400)
    api(node, "POST", "subfolders", {"path": "Work/.git"}, expect=400)
    api(node, "POST", "subfolders", {"path": "Empty"}, expect=400)
    api(node, "PATCH", "subfolders", {"path": "Work", "newPath": "Work/Inside"}, expect=400)
    api(node, "PATCH", "subfolders", {"path": "Missing", "newPath": "Other2"}, expect=404)
    api(node, "DELETE", "subfolders?path=", expect=400)

    moved, _, _ = api(node, "PATCH", "subfolders", {"path": "Empty/Deep", "newPath": "Deeper"})
    assert moved["folder"]["path"] == "Deeper"
    assert dav_exists(node, "Notes/Deeper")
    api(node, "DELETE", "subfolders?path=Deeper")
    api(node, "DELETE", "subfolders?path=Empty")
    assert not dav_exists(node, "Notes/Empty")


def test_tags(node, label):
    tags, _, _ = api(node, "GET", "tags")
    assert tags["etag"] is None
    assert tags["tags"] == []

    nested = find_note(node, "Nested")
    renamed = find_note(node, "Renamed note")

    # The first change creates notes.sqlite with the QOwnNotes Desktop schema
    note_tags, _, _ = api(node, "PUT", f"note/{nested['id']}/tags", {"tagPaths": [["Work", "Project A"], "Important"]})
    assert sorted(tuple(t["path"]) for t in note_tags["tags"]) == [("Important",), ("Work", "Project A")]
    etag = note_tags["etag"]
    assert etag
    assert sqlite(node, "SELECT value FROM appData WHERE name = 'database_version'") == "16"
    assert sqlite(node, "PRAGMA journal_mode") == "delete"
    assert sqlite(node, "PRAGMA quick_check") == "ok"
    assert tag_links(node) == ["Important|Nested.md|Work/Project|0", "Project A|Nested.md|Work/Project|0"]

    tags, _, _ = api(node, "GET", "tags")
    assert tags["etag"] == etag
    assert tags["writable"] is True
    assert tags["schemaVersion"] == 16
    top = {t["name"]: t for t in tags["tags"]}
    assert set(top) == {"Important", "Work"}
    assert top["Important"]["noteCount"] == 1
    assert top["Work"]["children"][0]["name"] == "Project A"
    assert top["Work"]["children"][0]["noteCount"] == 1

    # Changes need the current ETag of notes.sqlite
    api(node, "POST", "tags", {"name": "Archive"}, headers={"If-Match": '"outdated"'}, expect=412)
    created, headers, _ = api(node, "POST", "tags", {"name": "Archive", "color": "#FF8800"}, headers={"If-Match": f'"{etag}"'})
    assert created["tag"]["name"] == "Archive"
    assert created["tag"]["color"] == "#ff8800"
    assert headers["etag"].strip('"') == created["etag"] != etag
    api(node, "POST", "tags", {"name": "archive"}, expect=400)

    updated, _, _ = api(node, "PATCH", f"tags/{created['tag']['id']}", {"name": "Old stuff", "parentId": top["Work"]["id"]})
    assert updated["tag"]["path"] == ["Work", "Old stuff"]
    api(node, "PATCH", f"tags/{top['Work']['id']}", {"parentId": created["tag"]["id"]}, expect=400)

    # Batch: several changes in one transaction and one upload
    batch, _, _ = api(node, "POST", "tags/batch", {"operations": [
        {"op": "link", "noteId": renamed["id"], "tagId": top["Important"]["id"]},
        {"op": "link", "noteId": renamed["id"], "tagPath": ["Work", "Old stuff"]},
        {"op": "unlink", "noteId": nested["id"], "tagId": top["Important"]["id"]},
    ]})
    assert batch["etag"]
    assert tag_links(node) == ["Important|Renamed note.md|Work|0", "Old stuff|Renamed note.md|Work|0", "Project A|Nested.md|Work/Project|0"]
    api(node, "POST", "tags/batch", {"operations": [{"op": "explode"}]}, expect=400)
    assert len(tag_links(node)) == 3, "Failed batches must not change anything"

    links, _, _ = api(node, "GET", "tag-links")
    by_file = {link["fileName"]: link for link in links["links"]}
    assert by_file["Nested.md"]["noteId"] == nested["id"]
    assert by_file["Renamed note.md"]["noteId"] == renamed["id"]

    note_tags, _, _ = api(node, "GET", f"note/{renamed['id']}/tags")
    assert sorted(t["name"] for t in note_tags["tags"]) == ["Important", "Old stuff"]

    # Moving a subfolder moves the tag links of its notes
    moved, _, _ = api(node, "PATCH", "subfolders", {"path": "Work/Project", "newPath": "Archive/Project"})
    assert moved["tagsRelinked"] is True
    assert "Project A|Nested.md|Archive/Project|0" in tag_links(node)

    # The Notes API only relinks tags on request (QOwnNotes Android relinks them itself)
    api(node, "PUT", f"notes/{nested['id']}", {"title": "Nested moved"}, headers={"X-QOwnNotes-Relink-Tags": "1"})
    assert "Project A|Nested moved.md|Archive/Project|0" in tag_links(node)
    api(node, "PUT", f"notes/{nested['id']}", {"title": "Nested"})
    assert "Project A|Nested moved.md|Archive/Project|0" in tag_links(node)
    api(node, "PUT", f"notes/{nested['id']}", {"title": "Nested moved"})

    # With the relink header, relative links to media files are adapted to the new subfolder depth
    media_note, _, _ = api(node, "POST", "notes", {"title": "Media links", "content": "![i](media/x.png) [a](attachments/a.pdf)"})
    moved_note, _, _ = api(node, "PUT", f"notes/{media_note['id']}", {"category": "Archive/Media"}, headers={"X-QOwnNotes-Relink-Tags": "1"})
    assert moved_note["content"] == "![i](../../media/x.png) [a](../../attachments/a.pdf)", moved_note["content"]
    moved_note, _, _ = api(node, "PUT", f"notes/{media_note['id']}", {"category": "Archive"})
    assert moved_note["content"] == "![i](../../media/x.png) [a](../../attachments/a.pdf)", moved_note["content"]

    # Deleting a subfolder marks the links of its notes stale, QOwnNotes keeps them for 10 days
    deleted, _, _ = api(node, "DELETE", "subfolders?path=Archive")
    assert deleted["tagsUpdated"] is True
    assert "Project A|Nested moved.md|Archive/Project|1" in tag_links(node)
    note_tags, _, _ = api(node, "GET", f"note/{renamed['id']}/tags")
    assert len(note_tags["tags"]) == 2

    # Deleting a tag deletes its children and links
    tags, _, _ = api(node, "GET", "tags")
    work = next(t for t in tags["tags"] if t["name"] == "Work")
    api(node, "DELETE", f"tags/{work['id']}", headers={"If-Match": f'"{tags["etag"]}"'})
    assert tag_links(node) == ["Important|Renamed note.md|Work|0"]
    assert sqlite(node, "SELECT COUNT(*) FROM tag") == "1"

    # A notes.sqlite with an unknown newer schema is only read
    node.succeed(f"cp {NOTES_SQLITE} /tmp/notes.sqlite.bak")
    sqlite(node, "UPDATE appData SET value = '17' WHERE name = 'database_version'")
    # Changes within the same second have the same mtime and would not change the ETag of the file
    node.succeed(f"touch -d '+1 minute' {NOTES_SQLITE}")
    occ(node, "files:scan --path=admin/files/Notes")
    tags, _, _ = api(node, "GET", "tags")
    assert tags["writable"] is False
    assert [t["name"] for t in tags["tags"]] == ["Important"]
    api(node, "POST", "tags", {"name": "New"}, expect=503)
    node.succeed(f"cp /tmp/notes.sqlite.bak {NOTES_SQLITE} && chown nextcloud: {NOTES_SQLITE} && touch -d '+2 minutes' {NOTES_SQLITE}")
    occ(node, "files:scan --path=admin/files/Notes")


def ocs(node, path, expect=200):
    data, _, _ = http_json(node, "GET", f"{BASE_URL}/ocs/v2.php/{path}", expect=expect)
    return data["ocs"]["data"]


def test_note_history(node, label):
    """Note details, versions and the trash by note ID, as used by the web interface"""
    note, _, _ = api(node, "POST", "notes", {"title": "History", "category": "Hist", "content": "# History\n\nfirst"})
    node.succeed("sleep 1.1")
    api(node, "PUT", f"notes/{note['id']}", {"content": "# History\n\nsecond"})

    info, _, _ = api(node, "GET", f"note/{note['id']}/info")
    assert info["fileName"] == "History.md"
    assert info["subFolderPath"] == "Hist"
    assert info["path"] == "Notes/Hist/History.md"
    assert info["size"] == len("# History\n\nsecond")
    assert info["versionsAvailable"] is True and info["trashAvailable"] is True

    versions, _, _ = api(node, "GET", f"note/{note['id']}/versions")
    assert [version["data"] for version in versions["versions"]] == ["# History\n\nfirst"], versions
    assert "<del>second</del><ins>first</ins>" in versions["versions"][0]["diffHtml"]
    api(node, "GET", "note/999999/versions", expect=404)

    # A deleted note keeps its tags when it is restored
    api(node, "PUT", f"note/{note['id']}/tags", {"tagPaths": [["History tag"]]})
    api(node, "DELETE", f"notes/{note['id']}", headers={"X-QOwnNotes-Relink-Tags": "1"})
    assert "History tag|History.md|Hist|1" in tag_links(node)

    trash, _, _ = api(node, "GET", "trash")
    trashed = next(n for n in trash["notes"] if n["originalLocation"] == "Notes/Hist/History.md")
    assert trashed["title"] == "History" and trashed["subFolderPath"] == "Hist"
    assert trashed["content"] == "# History\n\nsecond"
    assert all(n["originalLocation"].startswith("Notes/") for n in trash["notes"]), "Only notes of the note folder are listed"

    api(node, "POST", "trash/restore", {"originalLocation": "Other/History.md", "deleted": trashed["deleted"]}, expect=400)
    restored, _, _ = api(node, "POST", "trash/restore", {"originalLocation": trashed["originalLocation"], "deleted": trashed["deleted"]}, headers={"X-QOwnNotes-Relink-Tags": "1"})
    assert restored["id"] is not None
    assert "History tag|History.md|Hist|0" in tag_links(node)
    note_tags, _, _ = api(node, "GET", f"note/{restored['id']}/tags")
    assert [tag["path"] for tag in note_tags["tags"]] == [["History tag"]]
    api(node, "POST", "trash/restore", {"originalLocation": trashed["originalLocation"], "deleted": trashed["deleted"]}, expect=404)


def integrations_enabled(node):
    """Which integrations of the web interface are available"""
    widgets = ocs(node, "apps/dashboard/api/v1/widgets")
    providers = ocs(node, "search/providers")
    reference_providers = ocs(node, "references/providers")
    _, _, files_page = http(node, "GET", f"{BASE_URL}/index.php/apps/files/", expect=200)
    return {
        "widget": "qownnotes-recent" in widgets,
        "search": any(p["id"] == "qownnotes" for p in providers),
        "reference": any(p["id"] == "qownnotes-note" for p in reference_providers),
        "files": "qownnotes-files" in files_page,
    }


def test_integrations(node, label):
    note, _, _ = api(node, "POST", "notes", {"title": "Integration note", "content": "# Integration note\n\nFind **me** here"})
    api(node, "PUT", f"note/{note['id']}/tags", {"tagPaths": [["Searchable", "Child"]]})
    assert integrations_enabled(node) == {"widget": True, "search": True, "reference": True, "files": True}

    items = ocs(node, "apps/dashboard/api/v2/widget-items?widgets[]=qownnotes-recent")["qownnotes-recent"]["items"]
    assert any(item["title"] == "Integration note" and item["link"].endswith(f"/apps/qownnotes/note/{note['id']}") for item in items), items

    results = ocs(node, "search/providers/qownnotes/search?term=" + quote("integration #searchable/child"))
    assert [entry["title"] for entry in results["entries"]] == ["Integration note"], results
    assert "#Searchable/Child" in results["entries"][0]["subline"]
    assert "Find me here" in results["entries"][0]["subline"]
    assert ocs(node, "search/providers/qownnotes/search?term=" + quote("#unknowntag"))["entries"] == []

    link = f"{BASE_URL}/index.php/apps/qownnotes/note/{note['id']}"
    reference = ocs(node, "references/resolve?reference=" + quote(link, safe=""))["references"][link]
    assert reference["richObject"]["name"] == "Integration note", reference
    assert reference["richObject"]["description"] == "Find me here"

    # All integrations of the web interface disappear in API-only mode
    occ_app_config(node, "config:app:set qownnotes ui_enabled --value=no")
    try:
        assert integrations_enabled(node) == {"widget": False, "search": False, "reference": False, "files": False}
        api(node, "GET", f"note/{note['id']}/info")
    finally:
        occ_app_config(node, "config:app:set qownnotes ui_enabled --value=yes")
    assert integrations_enabled(node)["search"] is True

    api(node, "DELETE", f"notes/{note['id']}")


def test_client_contracts(node, label):
    """Replay request shapes transcribed from Android and Desktop, with real versions and trash."""
    def expand(value, variables):
        if isinstance(value, str):
            return value.format_map(variables)
        if isinstance(value, dict):
            return {key: expand(item, variables) for key, item in value.items()}
        if isinstance(value, list):
            return [expand(item, variables) for item in value]
        return value

    def request(name, expect=200, **variables):
        template = expand(CLIENT_REQUESTS[name], variables)
        path = template["path"]
        if "query" in template:
            path += "?" + urlencode(template["query"], doseq=True)
        return api(node, template["method"], path, template.get("payload"), template.get("headers"), expect)[0]

    def assert_note(note, title, category, content, modified, favorite):
        assert type(note["id"]) is int and note["id"] > 0, note
        assert isinstance(note["etag"], str) and note["etag"], note
        assert note["readonly"] is False, note
        assert note["title"] == title and note["category"] == category, note
        assert note["content"] == content, note
        assert type(note["modified"]) is int and note["modified"] == modified, note
        assert note["favorite"] is favorite, note
        assert note["internalPath"] == f"/Contracts/Notes/{category}/{title}.qnote", note

    previous = request("settings")
    try:
        settings = request("updateSettings")
        assert settings["notesPath"] == "Contracts/Notes" and settings["fileSuffix"] == ".qnote"
        for client in ["android", "desktop"]:
            print(f"Nextcloud {label}: {client} request contracts")
            info = request(f"{client}AppInfo")
            assert info["notes_path_exists"] is True and info["versions_app"] is True and info["trash_app"] is True, info
            assert info["versioning"] is True and isinstance(info["app_version"], str), info

            note = request("createNote", client=client)
            assert_note(note, f"Contract {client}", "Work/Project", "# Contract\n\nOriginal", 1700000000, False)
            fetched = request("getNote", id=note["id"])
            assert fetched == note, fetched

            # A real WebDAV edit gives the versions app an old file revision to return.
            node.succeed("sleep 1.1")
            dav(node, "PUT", note["internalPath"], "# Contract\n\nRemote edit", expect=204)
            remote = request("getNote", id=note["id"])
            conflict = request("updateNote", expect=412, client=client, id=note["id"], etag=note["etag"])
            assert conflict == remote, conflict
            assert dav(node, "GET", note["internalPath"], expect=200)[2] == "# Contract\n\nRemote edit"

            remote_path = remote["internalPath"]
            versions = request("versions", remotePath=remote_path)
            assert versions["file_name"] == remote_path and versions["error_messages"] == [], versions
            assert any(version["data"] == "# Contract\n\nOriginal" for version in versions["versions"]), versions
            for version in versions["versions"]:
                assert type(version["timestamp"]) is int and version["timestamp"] > 0, version
                assert isinstance(version["humanReadableTimestamp"], str) and version["humanReadableTimestamp"], version
                assert isinstance(version["diffHtml"], str) and version["diffHtml"], version

            updated = request("updateNote", client=client, id=note["id"], etag=remote["etag"])
            assert updated["id"] == note["id"] and updated["etag"] != remote["etag"], updated
            assert_note(updated, f"Renamed {client}", "Other", "# Contract\n\nUpdated", 1700000001, True)
            excluded, _, _ = api(node, "GET", f"notes/{note['id']}?exclude=internalPath,content")
            assert "internalPath" not in excluded and "content" not in excluded, excluded
            request("deleteNote", id=note["id"])
            request("getNote", expect=404, id=note["id"])
            request("deleteNote", expect=404, id=note["id"])

            directory = "/Contracts/Notes/Other"
            if client == "desktop":
                directory += "/"
            trash = request("trash", directory=directory)
            deleted = next(item for item in trash["notes"] if item["fileName"] == f"Renamed {client}.qnote")
            assert deleted["noteName"] == f"Renamed {client}" and deleted["data"] == updated["content"], deleted
            assert type(deleted["timestamp"]) is int and deleted["timestamp"] > 0, deleted
            assert isinstance(deleted["dateString"], str) and deleted["dateString"], deleted
            remote_path = updated["internalPath"]
            restored = request("restore", remotePath=remote_path, timestamp=deleted["timestamp"])
            assert restored["result"] is True and restored["filename"] == deleted["fileName"], restored
            assert dav(node, "GET", updated["internalPath"], expect=200)[2] == updated["content"]
            assert all(item["fileName"] != deleted["fileName"] for item in request("trash", directory=directory)["notes"])
    finally:
        api(node, "PUT", "settings", {"notesPath": previous["notesPath"], "fileSuffix": previous["fileSuffix"]})


def test_version(node, label, pkg_version):
    print(f"Testing Nextcloud {label} ({pkg_version})")
    # Run one Nextcloud version at a time to keep memory usage low
    node.start()
    node.wait_for_unit("phpfpm-nextcloud.service")
    node.wait_for_unit("nginx.service")
    node.succeed("curl -fsSL http://localhost/status.php | grep 'installed' | grep 'true'")

    test_app_basics(node, label)
    test_web_interface(node, label)
    test_api_only_mode(node, label)
    test_notes_api_crud(node, label)
    test_notes_api_listing(node, label)
    test_notes_api_attachments(node, label)
    test_notes_api_settings(node, label)
    test_legacy_api(node, label, pkg_version)
    test_subfolders(node, label)
    test_tags(node, label)
    test_note_history(node, label)
    test_integrations(node, label)
    test_client_contracts(node, label)

    node.shutdown()
