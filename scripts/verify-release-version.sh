#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG_NAME="${1:-${GITHUB_REF_NAME:-}}"

if [[ -z "${TAG_NAME}" ]]; then
  echo "Release tag is required (example: v0.5.0)." >&2
  exit 1
fi

TAG_VERSION="${TAG_NAME#v}"
PLUGIN_VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${ROOT_DIR}/atum-mailer.php" | head -n1 | tr -d '[:space:]')"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${ROOT_DIR}/readme.txt" | head -n1 | tr -d '[:space:]')"
PACKAGE_VERSION="$(sed -n 's/^[[:space:]]*\"version\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p' "${ROOT_DIR}/package.json" | head -n1 | tr -d '[:space:]')"

if [[ -z "${PLUGIN_VERSION}" || -z "${STABLE_TAG}" || -z "${PACKAGE_VERSION}" ]]; then
  echo "Failed to read one or more version markers (plugin/readme/package)." >&2
  exit 1
fi

if [[ "${TAG_VERSION}" != "${PLUGIN_VERSION}" ]]; then
  echo "Tag version (${TAG_VERSION}) does not match plugin version (${PLUGIN_VERSION})." >&2
  exit 1
fi

if [[ "${TAG_VERSION}" != "${STABLE_TAG}" ]]; then
  echo "Tag version (${TAG_VERSION}) does not match readme stable tag (${STABLE_TAG})." >&2
  exit 1
fi

if [[ "${TAG_VERSION}" != "${PACKAGE_VERSION}" ]]; then
  echo "Tag version (${TAG_VERSION}) does not match package.json version (${PACKAGE_VERSION})." >&2
  exit 1
fi

echo "Release versions are aligned at ${TAG_VERSION}."
