# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

import base64
import hashlib
import importlib.util
import json
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import MagicMock, patch


def load(name):
    path = Path(__file__).resolve().parents[2] / "scripts" / f"{name}.py"
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


STORE = load("publish-appstore")
GITHUB = load("publish-github-release")
URL = "https://github.com/qownnotes/qownnotes-nextcloud/releases/download/v26.10.1/qownnotes-26.10.1.tar.gz"
COMMIT = "a" * 40
REPO = "qownnotes/qownnotes-nextcloud"


class AppStorePublishTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.archive = Path(self.temp.name) / "qownnotes-26.10.1.tar.gz"
        self.archive.write_bytes(b"signed archive")
        self.signature = self.archive.with_suffix(".sig")
        self.signature.write_text(base64.b64encode(b"signature").decode())

    def response(self, status=200, content=b"signed archive"):
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = status
        response.geturl.return_value = URL
        response.read.return_value = content
        return response

    def test_posts_existing_signature_and_token_only_to_store(self):
        for status in (200, 201):
            with (
                self.subTest(status=status),
                patch.object(
                    STORE.urllib.request,
                    "urlopen",
                    side_effect=[self.response(), self.response(status)],
                ) as request,
            ):
                self.assertEqual(
                    STORE.publish(URL, self.signature, self.archive, "test-token"),
                    status,
                )
                self.assertEqual(request.call_args_list[0].args[0], URL)
                post = request.call_args_list[1].args[0]
                self.assertEqual(post.full_url, STORE.ENDPOINT)
                self.assertEqual(post.get_header("Authorization"), "Token test-token")
                self.assertEqual(post.method, "POST")
                self.assertEqual(
                    json.loads(post.data),
                    {
                        "download": URL,
                        "signature": self.signature.read_text(),
                        "nightly": False,
                    },
                )

    def test_rejects_missing_token_bad_url_and_bad_signature_without_network(self):
        with patch.object(STORE.urllib.request, "urlopen") as request:
            for token in ("", "token\n"):
                with self.assertRaisesRegex(ValueError, "TOKEN"):
                    STORE.publish(URL, self.signature, self.archive, token)
            for url in (
                URL.replace("https:", "http:"),
                URL.replace("v26.10.1", "v26.10.0"),
                URL + "?token=secret",
            ):
                with self.assertRaisesRegex(ValueError, "Download URL"):
                    STORE.publish(url, self.signature, self.archive, "token")
            self.signature.write_text("not base64!")
            with self.assertRaises(ValueError):
                STORE.publish(URL, self.signature, self.archive, "token")
            request.assert_not_called()

    def test_rejects_changed_public_archive_without_posting(self):
        with patch.object(
            STORE.urllib.request,
            "urlopen",
            return_value=self.response(content=b"different"),
        ) as request:
            with self.assertRaisesRegex(ValueError, "differs"):
                STORE.publish(URL, self.signature, self.archive, "token")
            self.assertEqual(request.call_count, 1)

    def test_rejects_insecure_redirect(self):
        response = self.response()
        response.geturl.return_value = "http://example.com/archive"
        with (
            patch.object(STORE.urllib.request, "urlopen", return_value=response),
            self.assertRaisesRegex(ValueError, "insecure"),
        ):
            STORE.publish(URL, self.signature, self.archive, "token")

    def test_rejects_oversized_archive(self):
        with self.archive.open("wb") as archive:
            archive.truncate(STORE.MAX_SIZE + 1)
        with patch.object(STORE.urllib.request, "urlopen") as request:
            with self.assertRaisesRegex(ValueError, "20 MiB"):
                STORE.publish(URL, self.signature, self.archive, "token")
            request.assert_not_called()

    def test_store_http_error_propagates_for_failed_job(self):
        error = urllib.error.HTTPError(STORE.ENDPOINT, 400, "Bad request", {}, None)
        with (
            patch.object(
                STORE.urllib.request, "urlopen", side_effect=[self.response(), error]
            ),
            self.assertRaises(urllib.error.HTTPError),
        ):
            STORE.publish(URL, self.signature, self.archive, "token")


class GitHubPublishTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "build").mkdir()
        self.archive = self.root / "build/qownnotes-26.10.1.tar.gz"
        self.archive.write_bytes(b"signed archive")
        self.signature = self.archive.with_name(self.archive.name + ".sig")
        self.signature.write_text("signature")
        self.checksum = self.archive.with_name(self.archive.name + ".sha256")
        self.checksum.write_text(
            f"{hashlib.sha256(self.archive.read_bytes()).hexdigest()}  {self.archive.name}\n"
        )
        metadata = patch.object(
            GITHUB.subprocess, "check_output", return_value="26.10.1\n"
        )
        self.metadata = metadata.start()
        self.addCleanup(metadata.stop)

    def test_creates_draft_uploads_and_publishes_with_changelog(self):
        with patch.object(
            GITHUB, "gh", side_effect=[None, None, "", "", "", "", ""]
        ) as gh:
            GITHUB.publish(self.root, COMMIT, REPO)
        calls = [call.args for call in gh.call_args_list]
        self.assertIn("--notes-output", self.metadata.call_args.args[0])
        self.assertIn("--target", calls[2])
        self.assertIn(COMMIT, calls[2])
        self.assertIn("--draft", calls[2])
        self.assertEqual(sum(call[:2] == ("release", "upload") for call in calls), 3)
        self.assertIn("--notes-file", calls[-1])
        self.assertIn(str(self.root / "release-notes.md"), calls[-1])
        self.assertIn("--draft=false", calls[-1])
        self.assertFalse(any("--clobber" in call for call in calls))

    def test_refuses_tag_for_different_commit(self):
        with patch.object(
            GITHUB, "gh", side_effect=[None, json.dumps({"sha": "b" * 40})]
        ) as gh:
            with self.assertRaisesRegex(ValueError, "different commit"):
                GITHUB.publish(self.root, COMMIT, REPO)
            self.assertEqual(gh.call_count, 2)

    def test_refuses_draft_for_different_commit(self):
        release = {"draft": True, "target_commitish": "b" * 40, "assets": []}
        with (
            patch.object(GITHUB, "gh", side_effect=[json.dumps(release), None]),
            self.assertRaisesRegex(ValueError, "different commit"),
        ):
            GITHUB.publish(self.root, COMMIT, REPO)

    def test_refuses_modified_signed_archive(self):
        self.archive.write_bytes(b"tampered")
        with patch.object(GITHUB, "gh") as gh:
            with self.assertRaisesRegex(ValueError, "checksum"):
                GITHUB.publish(self.root, COMMIT, REPO)
            gh.assert_not_called()

    def test_published_release_is_not_modified(self):
        release = {"draft": False, "assets": []}
        with patch.object(
            GITHUB, "gh", side_effect=[json.dumps(release), json.dumps({"sha": COMMIT})]
        ) as gh:
            with self.assertRaisesRegex(ValueError, "refusing to modify"):
                GITHUB.publish(self.root, COMMIT, REPO)
            self.assertEqual(gh.call_count, 2)

    def test_partial_draft_resumes_identical_assets(self):
        release = {"draft": True, "assets": [{"name": self.archive.name}]}

        def gh(*args, **kwargs):
            if args[0] == "api":
                if "releases/tags" in args[1]:
                    return json.dumps(release)
                return json.dumps({"sha": COMMIT})
            if args[:2] == ("release", "download"):
                (Path(args[-1]) / self.archive.name).write_bytes(
                    self.archive.read_bytes()
                )
            return ""

        with patch.object(GITHUB, "gh", side_effect=gh) as mocked:
            GITHUB.publish(self.root, COMMIT, REPO)
        uploads = [
            call
            for call in mocked.call_args_list
            if call.args[:2] == ("release", "upload")
        ]
        self.assertEqual(len(uploads), 2)

    def test_identical_published_release_can_be_retried_without_mutation(self):
        assets = [self.archive, self.signature, self.checksum]
        release = {"draft": False, "assets": [{"name": asset.name} for asset in assets]}

        def gh(*args, **kwargs):
            if args[0] == "api":
                if "releases/tags" in args[1]:
                    return json.dumps(release)
                return json.dumps({"sha": COMMIT})
            if args[:2] == ("release", "download"):
                name = args[args.index("--pattern") + 1]
                (Path(args[-1]) / name).write_bytes(
                    (self.archive.parent / name).read_bytes()
                )
            return ""

        with patch.object(GITHUB, "gh", side_effect=gh) as mocked:
            GITHUB.publish(self.root, COMMIT, REPO)
        self.assertFalse(
            any(
                call.args[:2]
                in (("release", "upload"), ("release", "edit"), ("release", "create"))
                for call in mocked.call_args_list
            )
        )

    def test_refuses_different_existing_asset(self):
        release = {"draft": True, "assets": [{"name": self.archive.name}]}

        def gh(*args, **kwargs):
            if args[0] == "api":
                if "releases/tags" in args[1]:
                    return json.dumps(release)
                return json.dumps({"sha": COMMIT})
            if args[:2] == ("release", "download"):
                (Path(args[-1]) / self.archive.name).write_bytes(b"different")
            return ""

        with (
            patch.object(GITHUB, "gh", side_effect=gh) as mocked,
            self.assertRaisesRegex(ValueError, "overwrite different"),
        ):
            GITHUB.publish(self.root, COMMIT, REPO)
        self.assertFalse(
            any(
                call.args[:2] == ("release", "upload") for call in mocked.call_args_list
            )
        )

    def test_cli_errors_are_not_treated_as_missing_releases(self):
        result = MagicMock(returncode=1, stderr="HTTP 403", stdout="")
        with patch.object(GITHUB.subprocess, "run", return_value=result):
            with self.assertRaises(ValueError):
                GITHUB.gh(
                    "api", "repos/example/releases/tags/v26.10.1", allow_missing=True
                )
            result.stderr = "HTTP 404"
            self.assertIsNone(
                GITHUB.gh(
                    "api", "repos/example/releases/tags/v26.10.1", allow_missing=True
                )
            )


if __name__ == "__main__":
    unittest.main()
