# Nextcloud development environment

```bash
npm ci && npm run watch  # build the frontend into js/
cd docker
just up                  # Nextcloud on http://localhost:8081 (admin/admin) with the app mounted
```

Run `just open-browser` from the project root to open the QOwnNotes app in your browser.

The default is Nextcloud 35. Use another version with `NEXTCLOUD_VERSION=32 just up` (after `just reset`, because downgrades are not possible; resetting deletes the dev server's data).

When upgrading an existing dev server, upgrade one major version at a time (for example, 33 → 34 → 35).

Browser upgrades are enabled for this development environment by `zz-development.config.php`, overriding the
Docker image's default. If Nextcloud shows "Update needed", you can continue in the browser or run
`just occ upgrade` from this directory. Do not use this override in production; browser upgrades can time out.

`just sign-app` creates the signed release archive with the `occ` of this container, see [docs/release.md](../docs/release.md).

The end-to-end tests in `tests/e2e` and the API integration tests in `tests/integration` run against this server, see [tests/e2e/README.md](../tests/e2e/README.md) and [tests/integration/README.md](../tests/integration/README.md).
