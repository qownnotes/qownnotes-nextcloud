# End-to-end tests

Playwright smoke tests of the web interface. They run against the docker development server (see
[docker/README.md](../../docker/README.md)) with the user `admin`/`admin` and create their own notes, folders and tags.

```bash
npm ci && npm run build   # in the repository root
cd docker && just up      # in another terminal
just test-e2e             # uses the system Chromium if available (NixOS), otherwise run `npx playwright install chromium`
```

Use `NEXTCLOUD_URL`, `NEXTCLOUD_USER` and `NEXTCLOUD_PASSWORD` to test against another server.
