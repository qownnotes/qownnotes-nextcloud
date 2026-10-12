# End-to-end tests

Playwright smoke tests of the web interface. They run against the docker development server (see
[docker/README.md](../../docker/README.md)) with the user `admin`/`admin` and create their own notes, folders and tags.

```bash
npm ci && npm run build   # in the repository root
cd docker && just up      # in another terminal
just test-e2e             # uses the system Chromium if available (NixOS), otherwise run `npx playwright install chromium`
```

Use `NEXTCLOUD_URL`, `NEXTCLOUD_USER` and `NEXTCLOUD_PASSWORD` to test against another server.

## Screenshots

`just screenshots` creates the app store and README screenshots in [docs/screenshots/](../../docs/screenshots/) with
[screenshots/screenshots.spec.js](screenshots/screenshots.spec.js) and the same server. It recreates the user `demo`
(with the admin account), fills its note folder with demo notes, tags and tag colors, and takes the screenshots at
1440×900 in the light theme. The PNG files are optimized with `oxipng` if it is installed. Review the images before
committing them; the app store loads them from the `main` branch. New screenshots also need a `<screenshot>` entry in
`appinfo/info.xml`.
