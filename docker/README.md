# Nextcloud development environment

```bash
npm ci && npm run watch  # build the frontend into js/
cd docker
just up                  # Nextcloud on http://localhost:8081 (admin/admin) with the app mounted
```

Run `just open-browser` from the project root to open the QOwnNotes app in your browser.

The default is Nextcloud 35. Use another version with `NEXTCLOUD_VERSION=32 just up` (after `just reset`, because downgrades are not possible; resetting deletes the dev server's data).

When upgrading an existing dev server, upgrade one major version at a time (for example, 33 → 34 → 35).

`just sign-app` creates the signed release archive with the `occ` of this container, see [docs/release.md](../docs/release.md).

The end-to-end tests in `tests/e2e` run against this server, see [tests/e2e/README.md](../tests/e2e/README.md).
