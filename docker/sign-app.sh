#!/bin/sh
# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Creates the signed release archive of the app for the Nextcloud app store.
# Runs as root in the Nextcloud container of docker-compose.yml, see `just sign-app` in docker/justfile.
# Needs the built frontend in js/ and qownnotes.key and qownnotes.crt in /var/www/certificates.

set -eu

APP_NAME=qownnotes
APP_SOURCE=/var/www/html/custom_apps/${APP_NAME}
CERT_DIR=/var/www/certificates
BUILD_DIR=${APP_SOURCE}/build

for file in "${CERT_DIR}/${APP_NAME}.key" "${CERT_DIR}/${APP_NAME}.crt"; do
  if [ ! -f "${file}" ]; then
    echo "❌ ${file} is missing, see docs/release.md" >&2
    exit 1
  fi
done

if [ ! -f "${APP_SOURCE}/js/${APP_NAME}-main.mjs" ]; then
  echo "❌ The frontend is not built, run \`npm ci && npm run build\` first" >&2
  exit 1
fi

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "${APP_SOURCE}/appinfo/info.xml" | head -n 1)
WORK_DIR=$(mktemp -d)
APP_DEST=${WORK_DIR}/${APP_NAME}
ARCHIVE=${BUILD_DIR}/${APP_NAME}-${VERSION}.tar.gz
trap 'rm -rf "${WORK_DIR}"' EXIT

mkdir "${APP_DEST}"
cd "${APP_SOURCE}"
# Only the files listed in release-files.txt are released
sources=""
while IFS= read -r file; do
  case "${file}" in
  '' | '#'*) continue ;;
  esac
  if [ -e "${file}" ]; then
    sources="${sources} ${file}"
  fi
done <release-files.txt
# shellcheck disable=SC2086 # the list of sources must be split
rsync -a --exclude '.*' --exclude 'appinfo/signature.json' ${sources} "${APP_DEST}/"
echo "📂 Files of version ${VERSION} copied"

if [ -n "$(find "${APP_DEST}" -type l)" ]; then
  echo "❌ Symlinks are not allowed in the release:" >&2
  find "${APP_DEST}" -type l >&2
  exit 1
fi
find "${APP_DEST}" -type d -empty -delete

# occ runs as www-data, which needs to read the key
mkdir "${WORK_DIR}/certificates"
cp "${CERT_DIR}/${APP_NAME}.key" "${CERT_DIR}/${APP_NAME}.crt" "${WORK_DIR}/certificates/"
chmod 600 "${WORK_DIR}/certificates/${APP_NAME}.key"
chown -R www-data:www-data "${WORK_DIR}"

su -s /bin/sh www-data -c "php /var/www/html/occ integrity:sign-app \
  --privateKey=${WORK_DIR}/certificates/${APP_NAME}.key \
  --certificate=${WORK_DIR}/certificates/${APP_NAME}.crt \
  --path=${APP_DEST}"
echo "🔐 App signed"

printf "\n🔍 Files in the archive:\n\n"
(cd "${WORK_DIR}" && find "${APP_NAME}" -type f ! -name '*.chunk.mjs*' | sort)
printf "\n... and %s code chunks in js/\n\n" "$(find "${APP_DEST}/js" -name '*.chunk.mjs' | wc -l)"

mkdir -p "${BUILD_DIR}"
tar czf "${ARCHIVE}" --owner=0 --group=0 --numeric-owner -C "${WORK_DIR}" "${APP_NAME}"
openssl dgst -sha512 -sign "${WORK_DIR}/certificates/${APP_NAME}.key" "${ARCHIVE}" | openssl base64 -A >"${ARCHIVE}.sig"
chown -R "$(stat -c '%u:%g' "${APP_SOURCE}")" "${BUILD_DIR}"

echo "📦 Archive: build/$(basename "${ARCHIVE}") ($(($(stat -c '%s' "${ARCHIVE}") / 1024)) KiB)"
printf "\n🔐 Signature of the archive for the app store (also in build/%s.sig):\n\n" "$(basename "${ARCHIVE}")"
cat "${ARCHIVE}.sig"
echo
