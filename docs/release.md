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

1. Set the version in `appinfo/info.xml` and `package.json`, and add the release notes to `CHANGELOG.md` under `## <version>`.
2. Make sure CI is green (format check and VM test).
3. Push the commit to the `release` branch. The workflow `create_release.yml` creates a draft GitHub release `v<version>` with the release notes.
4. Create the signed release archive. This builds the frontend and signs the app in the docker container of the dev environment (create it with `cd docker && just up` first):

   ```bash
   just sign-app
   ```

   The archive only contains the files listed in [`release-files.txt`](../release-files.txt). It is stored as `build/qownnotes-<version>.tar.gz`, its signature for the app store as `build/qownnotes-<version>.tar.gz.sig`.

5. Attach the archive to the draft release and publish it.
6. Upload the release in the [app store](https://apps.nextcloud.com/developer/apps/releases/new) with the download URL of the archive (`https://github.com/qownnotes/qownnotes-nextcloud/releases/download/v<version>/qownnotes-<version>.tar.gz`) and the signature.
