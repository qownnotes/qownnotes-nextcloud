# NixOS VM test script; the "test_version" calls are appended by basic.nix
# pyright: reportUndefinedVariable=false
import base64
import json
import shlex

BASE_URL = "http://localhost"
APP_URL = f"{BASE_URL}/index.php/apps/qownnotes"
API_URL = f"{APP_URL}/api/v1"
AUTH = "admin:adminpass"


def occ(node, command):
    return node.succeed(f"sudo -u nextcloud nextcloud-occ {command}")


def http(node, method, url, data=None, headers=None, expect=None):
    """Runs an HTTP request and returns (status, headers, body)"""
    command = f"curl -sS -u {AUTH} -X {method} -D /tmp/headers -o /tmp/body -w '%{{http_code}}'"
    for name, value in (headers or {}).items():
        command += " -H " + shlex.quote(f"{name}: {value}")
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


def test_version(node, label, pkg_version):
    print(f"Testing Nextcloud {label} ({pkg_version})")
    # Run one Nextcloud version at a time to keep memory usage low
    node.start()
    node.wait_for_unit("phpfpm-nextcloud.service")
    node.wait_for_unit("nginx.service")
    node.succeed("curl -fsSL http://localhost/status.php | grep 'installed' | grep 'true'")

    test_app_basics(node, label)
    test_api_only_mode(node, label)

    node.shutdown()
