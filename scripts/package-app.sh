#!/bin/sh
# SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

# Package an unsigned, installable app after building the frontend.
set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
APP_SOURCE=${1:-$(dirname "${SCRIPT_DIR}")}
APP_SOURCE=$(CDPATH='' cd -- "${APP_SOURCE}" && pwd)
VERSION=$(python3 "${SCRIPT_DIR}/check-release.py" --root "${APP_SOURCE}" --built)
WORK_DIR=$(mktemp -d)
trap 'rm -rf "${WORK_DIR}"' EXIT
APP_DEST=${WORK_DIR}/qownnotes
mkdir "${APP_DEST}"

cd "${APP_SOURCE}"
while IFS= read -r file; do
  case "${file}" in
  '' | '#'*) continue ;;
  esac
  rsync -a --exclude '.*' --exclude 'appinfo/signature.json' "${file}" "${APP_DEST}/"
done <release-files.txt
find "${APP_DEST}" -type d -empty -delete

mkdir -p build
ARCHIVE=qownnotes-continuous.tar.gz
tar czf "build/${ARCHIVE}" --owner=0 --group=0 --numeric-owner -C "${WORK_DIR}" qownnotes
(cd build && sha256sum "${ARCHIVE}" >"${ARCHIVE}.sha256")
echo "Packaged unsigned app ${VERSION}: build/${ARCHIVE}"
