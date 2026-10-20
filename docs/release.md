# Releasing

The app uses CalVer versions (`YY.M.patch`, e.g. `26.10.0`).

## One-time setup

1. Create a private key and a certificate signing request for the app ID `qownnotes` and get it signed by Nextcloud, see [App code signing](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#obtaining-a-certificate):

   ```bash
   mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
    openssl req -nodes -newkey rsa:4096 -keyout qownnotes.key -out qownnotes.csr -subj "/CN=qownnotes"
    chmod 600 qownnotes.key
   ```

2. Store the signed certificate as `~/.nextcloud/certificates/qownnotes.crt` and register the app in the [app store](https://apps.nextcloud.com/developer/apps/new) with it. Registration also requires a signature over the app ID:

   ```bash
   printf qownnotes | openssl dgst -sha512 -sign ~/.nextcloud/certificates/qownnotes.key | openssl base64 -A
   ```

3. Another certificate folder can be used with the environment variable `QOWNNOTES_CERTIFICATE_DIR`.

## GitHub Actions setup

Create these environments under **Settings → Environments**:

| Environment          | Secrets                             | Purpose                                                                                       |
| -------------------- | ----------------------------------- | --------------------------------------------------------------------------------------------- |
| `release-signing`    | `APP_PRIVATE_KEY`, `APP_PUBLIC_CRT` | Full PEM contents of `qownnotes.key` and `qownnotes.crt`                                      |
| `release-publishing` | `APPSTORE_TOKEN`                    | App Store account API token from [account settings](https://apps.nextcloud.com/account/token) |

The certificate is public; storing it as an environment secret is just convenient. The private key and token must never be committed, uploaded as artifacts or put in logs. Keep an offline backup of the key. The App Store token can affect other apps managed by that account, so treat it as a sensitive account credential.

Restrict both environments to the `release` branch and configure required reviewers where your GitHub plan supports them. Protect the `release` branch against unreviewed changes and force pushes, especially workflow/script changes. Environments are not a substitute for reviewing the code that receives secrets. The signing and publishing jobs use GitHub-hosted ephemeral runners; dependency builds and tests run in separate jobs without these secrets. Only the GitHub publishing job has `contents: write`.

## Automated release

The **Sign and publish release** workflow (`.github/workflows/create_release.yml`) runs **only on pushes to the `release` branch**, not tags, pull requests or ordinary development pushes. There is no manual-dispatch trigger. Continuous builds remain separate and unsigned.

1. Set the version in `appinfo/info.xml`, `package.json` and **both** root version fields in `package-lock.json`. Put nonempty release notes under `## <version>` as the newest section in `CHANGELOG.md`.
2. Run `python3 scripts/check-release.py` locally. It checks CalVer, matching package/lock versions, exactly one matching changelog section, the newest changelog version, manifest inputs and repository-backed screenshots. Dependency versions within lockfiles are not app versions; Composer files currently have no app-version field. `just release-check`, which runs on every push, and the continuous build (`scripts/package-app.sh`) use `--allow-unreleased`, so between releases the changelog can have a single leading `## Unreleased` section or sections for newer versions above the current one; the release itself always requires the newest section to match.
3. Commit the changes and push that reviewed commit to `release`. Do not push another release while one is awaiting approval. The workflow prevents concurrent release jobs and does not cancel an active release.
4. The explicit **Check all release versions and extract changelog** step runs before building or accessing secrets. A mismatch fails the release. Frontend lint/tests/build and the reusable formatting workflow (including PHP syntax/style/Psalm/unit checks for releases) must pass before signing. The standalone Nextcloud VM test workflow runs independently and is not part of the release workflow. No rebuild happens in the secret-bearing jobs.
5. Approve the `release-signing` deployment. `scripts/ci-sign-app.sh` starts an isolated Nextcloud 35.0.1 container, uses `docker/sign-app.sh` to sign the manifest-selected runtime files, then verifies the archive signature with OpenSSL. It extracts the actual archive, requires `appinfo/signature.json`, runs Nextcloud's integrity check, installs that extracted app and checks the legacy app-info API/version. The container and temporary key files are cleaned up on exit. It does not touch development Docker volumes.
6. Approve publishing when prompted. `scripts/publish-github-release.py` checks the signed archive checksum, creates or resumes a draft tagged `v<version>` at the exact workflow commit, attaches the archive, `.sig` and `.sha256`, then publishes it. **The matching changelog section is the GitHub release description**; it is not generated from commits. Existing tags are never moved and existing assets are never overwritten with different bytes.
7. `scripts/publish-appstore.py` verifies that the public GitHub download is byte-identical to the signed artifact, then submits its URL and existing signature to the [App Store API](https://nextcloudappstore.readthedocs.io/en/latest/api/restapi.html#api-create-release) with `nightly: false`. This job has no private key. The App Store reads metadata and release notes from the archive. Archives over its documented 20 MiB limit are rejected by the script.

Signed artifacts and unsigned build inputs are retained for 14 days in Actions. GitHub release assets remain available afterwards. Third-party actions and the signing image are pinned; update them deliberately. The reusable format/VM workflows also need their dependencies reviewed when changed.

### Failures and retries

- **Validation/tests fail:** fix the problem and push a reviewed commit to `release`; no signing or publication occurs.
- **Signing fails:** check environment secrets and the app-specific certificate. Re-run failed jobs after correcting configuration.
- **GitHub publishing fails:** re-run failed jobs while the signed Actions artifact is retained. A partially uploaded draft can be resumed, but different existing assets or a tag/draft targeting another commit stop publication. Do not re-run all jobs after assets have been uploaded: rebuilt/re-signed archives can have different bytes even for the same commit.
- **App Store submission fails:** the GitHub release stays published. Fix registration/token/download issues and select **Re-run failed jobs**, not all jobs. This reuses the exact signed artifact without rebuilding or signing. A retry may return HTTP 200 (updated) instead of HTTP 201 (created).

After Actions artifacts expire, download the archive and `.sig` from the published GitHub release and submit them locally:

```bash
# Export APPSTORE_TOKEN securely; do not put the token in shell history.
python3 scripts/publish-appstore.py \
  --download-url https://github.com/qownnotes/qownnotes-nextcloud/releases/download/v26.10.1/qownnotes-26.10.1.tar.gz \
  --archive build/qownnotes-26.10.1.tar.gz \
  --signature build/qownnotes-26.10.1.tar.gz.sig
```

Replace the example version as appropriate. Never replace published assets to fix a release: ship a new version. API failures produce a failing job; automatic publication does not replace the first-release client acceptance checks below.

## Local signing fallback

1. Set the version in `appinfo/info.xml`, `package.json` and both root version fields in `package-lock.json`, and add the release notes to `CHANGELOG.md` under `## <version>`.
2. Run `just release-check` and make sure CI is green (format check and VM test). This runs without certificates, Docker or a frontend build. `just sign-app` additionally checks the built frontend before signing.
3. Create the signed release archive. This builds the frontend and signs the app in the docker container of the dev environment (create it with `cd docker && just up` first):

   ```bash
   just sign-app
   ```

   The archive only contains the files listed in [`release-files.txt`](../release-files.txt). It is stored as `build/qownnotes-<version>.tar.gz`, its signature for the app store as `build/qownnotes-<version>.tar.gz.sig`.

   Every manifest entry must exist. Styles are part of the frontend bundle in `js/`; generated translations in `l10n/` are also included. Run `python3 scripts/generate-translations.py --check` to verify that the catalogs match their sources (see [translations](translations.md)).

4. Verify the signed archive in Nextcloud before publication. For a wholly local release, create the GitHub release manually, use the matching changelog section for its description and attach the archive. Do not race the automated workflow or upload a locally rebuilt archive into a CI-created release. If using the local path instead of CI, do not push to `release` until you have intentionally disabled the automated workflow.
5. Upload the release in the [app store](https://apps.nextcloud.com/developer/apps/releases/new) with the public download URL of the archive (`https://github.com/qownnotes/qownnotes-nextcloud/releases/download/v<version>/qownnotes-<version>.tar.gz`) and the signature, or use `scripts/publish-appstore.py` as above.

## Continuous builds

The `build.yml` workflow builds the frontend on pushes to every branch except `release` (not tags), and can also be run manually on those branches. It packages only the runtime files in `release-files.txt`, with the built frontend and without any existing app signature.

Each run uploads `qownnotes-continuous.tar.gz` and its SHA-256 checksum as a `qownnotes-<commit>` workflow artifact, retained for 14 days. The archive contains a top-level `qownnotes/` folder ready to extract into Nextcloud's `custom_apps/` directory. To package locally after `npm ci && npm run build`, run `sh scripts/package-app.sh`.

On `main` only, a dependent job downloads that exact artifact (without rebuilding), verifies its checksum and publishes it to the rolling GitHub pre-release tagged `continuous`. The tag moves to the built commit and the assets are replaced on each successful run. It is not marked as the latest stable release.

These development builds are **unsigned**, intended for testing, and may produce a Nextcloud integrity warning. They are not suitable for app store publication. The signed `release` branch process above is unchanged; no signing credentials are needed by this workflow.

## First-release checklist

- [x] App store metadata, screenshots, release manifest, signing scripts and approval-gated release workflow are in the repository.
- [x] Local release preflight and its regression tests are shared with CI.
- [ ] Obtain the app-specific `qownnotes` certificate and register the app. A certificate for another app ID cannot be reused.
- [ ] Verify the signed archive with Nextcloud, upload it to a GitHub release and publish it in the app store.
- [ ] Ship Desktop and Android capability-discovery changes with legacy fallback (see [the plan](plan.md#10-client-changes-and-migration)); record the first supported client versions in the migration guide.
- [x] Capture genuine schema-15 and Android-written databases with provenance and compatibility tests.
- [ ] Perform the manual client sync checks described in the plan.

The preflight does not claim that signing, app store publication or client acceptance testing has succeeded. Those require external credentials or client runtimes and are separate release gates.
