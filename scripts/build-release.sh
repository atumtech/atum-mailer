#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
PACKAGE_DIR="${DIST_DIR}/atum-mailer"
ZIP_PATH="${DIST_DIR}/atum-mailer.zip"

rm -rf "${PACKAGE_DIR}" "${ZIP_PATH}"
mkdir -p "${PACKAGE_DIR}"

rsync -a "${ROOT_DIR}/" "${PACKAGE_DIR}/" \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.vscode' \
  --exclude '.idea' \
  --exclude '.DS_Store' \
  --exclude '.phpunit.result.cache' \
  --exclude 'scripts' \
  --exclude 'package.json' \
  --exclude 'package-lock.json' \
  --exclude 'tests' \
  --exclude 'dist' \
  --exclude 'phpunit.xml.dist'

(
  cd "${DIST_DIR}"
  zip -rq atum-mailer.zip atum-mailer
)

echo "Built ${ZIP_PATH}"
