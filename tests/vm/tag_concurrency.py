# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Real parallel HTTP writers; run inside the VM, not through shared curl files."""

import base64
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import sqlite3
import tempfile
from threading import Barrier
from urllib.error import HTTPError
from urllib.request import Request, urlopen


API = "http://localhost/index.php/apps/qownnotes/api/v1/"
DAV = "http://localhost/remote.php/dav/files/admin/Concurrency/notes.sqlite"
AUTH = "Basic " + base64.b64encode(b"admin:adminpass").decode()


def request(method, url, payload=None, etag=None):
    headers = {"Authorization": AUTH, "OCS-APIRequest": "true"}
    if isinstance(payload, dict):
        payload = json.dumps(payload).encode()
        headers["Content-Type"] = "application/json"
    if etag:
        headers["If-Match"] = f'"{etag.strip(chr(34))}"'
    req = Request(url, data=payload, headers=headers, method=method)
    try:
        response = urlopen(req, timeout=90)
    except HTTPError as error:
        response = error
    with response:
        return response.status, dict(response.headers), response.read()


def api(method, path, payload=None, etag=None):
    status, headers, body = request(method, API + path, payload, etag)
    return status, headers, json.loads(body) if body else None


def parallel(first, second):
    barrier = Barrier(2, timeout=30)

    def run(action):
        barrier.wait()
        return action()

    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(run, action) for action in [first, second]]
        return [future.result(timeout=120) for future in futures]


def tag_names():
    status, _, data = api("GET", "tags")
    assert status == 200, (status, data)
    return {tag["name"] for tag in data["tags"]}, data["etag"]


def main():
    status, _, original = api("GET", "settings")
    assert status == 200
    status, _, body = api("PUT", "settings", {"notesPath": "Concurrency"})
    assert status == 200, (status, body)
    try:
        for iteration in range(3):
            request("DELETE", DAV)
            status, _, body = api("POST", "tags", {"name": "Baseline"})
            assert status == 200, (status, body)
            _, etag = tag_names()
            names = [f"API A {iteration}", f"API B {iteration}"]
            results = parallel(
                lambda: api("POST", "tags", {"name": names[0]}, etag),
                lambda: api("POST", "tags", {"name": names[1]}, etag),
            )
            statuses = [result[0] for result in results]
            assert statuses.count(200) == 1 and all(s in [200, 412, 423] for s in statuses), results
            stored, _ = tag_names()
            assert stored == {"Baseline", names[statuses.index(200)]}, (statuses, stored)

            # Both writers use the same *file* ETag (not the tag-tree response ETag).
            status, headers, content = request("GET", DAV)
            assert status == 200
            dav_etag = next(value for name, value in headers.items() if name.lower() == "etag")
            with tempfile.TemporaryDirectory(prefix="qownnotes-race-") as directory:
                database = Path(directory) / "notes.sqlite"
                database.write_bytes(content)
                with sqlite3.connect(database) as db:
                    db.execute("INSERT INTO tag (name) VALUES ('DAV writer')")
                uploaded = database.read_bytes()
            prefix = f"API batch {iteration} "
            operations = [{"op": "create", "name": prefix + str(i)} for i in range(100)]
            results = parallel(
                lambda: api("POST", "tags/batch", {"operations": operations}, dav_etag),
                lambda: request("PUT", DAV, uploaded, dav_etag),
            )
            statuses = [result[0] for result in results]
            assert sum(s in [200, 204] for s in statuses) == 1, results
            assert statuses[0] in [200, 412, 423] and statuses[1] in [204, 412, 423], results
            final, _ = tag_names()
            expected = stored | ({op["name"] for op in operations} if statuses[0] == 200 else {"DAV writer"})
            assert final == expected, (statuses, final, expected)
            status, _, content = request("GET", DAV)
            assert status == 200 and content[18:20] == b"\x01\x01"
            with tempfile.TemporaryDirectory(prefix="qownnotes-check-") as directory:
                database = Path(directory) / "notes.sqlite"
                database.write_bytes(content)
                with sqlite3.connect(database) as db:
                    assert db.execute("PRAGMA quick_check").fetchone() == ("ok",)
            print(f"Concurrent API/API and API/DAV writes passed: round {iteration + 1}", flush=True)
    finally:
        request("DELETE", DAV)
        status, _, body = api("PUT", "settings", {"notesPath": original["notesPath"]})
        assert status == 200, (status, body)


if __name__ == "__main__":
    main()
