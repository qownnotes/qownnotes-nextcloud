# Releasing

The app uses CalVer versions (`YY.M.patch`, e.g. `26.10.0`).

## One-time setup

1. Create a private key and a certificate signing request for the app ID `qownnotes` and get it signed by Nextcloud, see [App code signing](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#obtaining-a-certificate):

   ```bash
   mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 -keyout qownnotes.key -out qownnotes.csr -subj "/CN=qownnotes"
   ```

2. Store the signed certificate as `~/.nextcloud/certificates/qownnotes.crt` and register the app in the [app store](https://apps.nextcloud.com/developer/apps/new) with it.
3. Another certificate folder can be used with the environment variable `QOWNNOTES_CERTIFICATE_DIR`.

## Release

1. Set the version in `appinfo/info.xml`, `package.json` and both root version fields in `package-lock.json`, and add the release notes to `CHANGELOG.md` under `## <version>`.
2. Run `just release-check` and make sure CI is green (format check and VM test). The check validates CalVer, matching versions, nonempty release notes, manifest inputs and repository-backed app store screenshots. It runs without certificates, Docker or a frontend build. `just sign-app` additionally checks the built frontend before signing; the draft-release workflow uses the same checker to extract the release notes.
3. Push the commit to the `release` branch. The workflow `create_release.yml` creates a draft GitHub release `v<version>` with the release notes.
4. Create the signed release archive. This builds the frontend and signs the app in the docker container of the dev environment (create it with `cd docker && just up` first):

   ```bash
   just sign-app
   ```

   The archive only contains the files listed in [`release-files.txt`](../release-files.txt). It is stored as `build/qownnotes-<version>.tar.gz`, its signature for the app store as `build/qownnotes-<version>.tar.gz.sig`.

   Every manifest entry must exist. Styles are part of the frontend bundle in `js/`; add `l10n` to the manifest when generated translations are introduced (there are none yet).

5. Attach the archive to the draft release and publish it.
6. Upload the release in the [app store](https://apps.nextcloud.com/developer/apps/releases/new) with the download URL of the archive (`https://github.com/qownnotes/qownnotes-nextcloud/releases/download/v<version>/qownnotes-<version>.tar.gz`) and the signature.

## Continuous builds

The `build.yml` workflow builds the frontend on pushes to every branch except `release` (not tags), and can also be run manually on those branches. It packages only the runtime files in `release-files.txt`, with the built frontend and without any existing app signature.

Each run uploads `qownnotes-continuous.tar.gz` and its SHA-256 checksum as a `qownnotes-<commit>` workflow artifact, retained for 14 days. The archive contains a top-level `qownnotes/` folder ready to extract into Nextcloud's `custom_apps/` directory. To package locally after `npm ci && npm run build`, run `sh scripts/package-app.sh`.

On `main` only, a dependent job downloads that exact artifact (without rebuilding), verifies its checksum and publishes it to the rolling GitHub pre-release tagged `continuous`. The tag moves to the built commit and the assets are replaced on each successful run. It is not marked as the latest stable release.

These development builds are **unsigned**, intended for testing, and may produce a Nextcloud integrity warning. They are not suitable for app store publication. The signed `release` branch process above is unchanged; no signing credentials are needed by this workflow.

## First-release checklist

- [x] App store metadata, screenshots, release manifest, signing script and draft-release workflow are in the repository.
- [x] Local release preflight and its regression tests are shared with CI.
- [ ] Obtain the app-specific `qownnotes` certificate and register the app. A certificate for another app ID cannot be reused.
- [ ] Verify the signed archive with Nextcloud, upload it to a GitHub release and publish it in the app store.
- [ ] Ship Desktop and Android capability-discovery changes with legacy fallback (see [the plan](plan.md#10-client-changes-and-migration)); record the first supported client versions in the migration guide.
- [x] Capture genuine schema-15 and Android-written databases with provenance and compatibility tests.
- [ ] Perform the manual client sync checks described in the plan.

The preflight does not claim that signing, app store publication or client acceptance testing has succeeded. Those require external credentials or client runtimes and are separate release gates.
