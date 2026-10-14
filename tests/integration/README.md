# API integration tests

PHPUnit tests with Guzzle that call the Notes API, the QOwnNotes API (1.0 and 1.1), WebDAV and OCS of a running Nextcloud server with the app enabled. They check the documented contracts in `docs/api/` against a real server, complementing the NixOS VM test (`tests/vm/`), and also cover the Nextcloud version of the docker dev server (Nextcloud 35 by default), which the VM test can't run yet.

```bash
cd docker && just up     # Nextcloud on http://localhost:8081 (admin/admin) with the app mounted
just test-integration    # from the project root
just test-integration --filter NotesApiTest
```

Every test class creates its own user through the OCS provisioning API and deletes it afterwards, so the tests don't touch the admin's notes and can run next to the end-to-end tests.

| Variable                   | Default                 |
| -------------------------- | ----------------------- |
| `NEXTCLOUD_URL`            | `http://localhost:8081` |
| `NEXTCLOUD_ADMIN_USER`     | `admin`                 |
| `NEXTCLOUD_ADMIN_PASSWORD` | `admin`                 |

The official Nextcloud image only checks PHP files for changes every 60 seconds (`opcache.revalidate_freq`), so wait a minute after changing PHP code before running the tests, or restart the container.

Tests that need the Versions or Deleted files app are skipped if the app is disabled.
