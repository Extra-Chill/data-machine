#!/usr/bin/env bash

set -euo pipefail

REPO="Extra-Chill/homeboy"
ASSET_RETRY_ATTEMPTS="${HOMEBOY_ASSET_RETRY_ATTEMPTS:-3}"
ASSET_RETRY_DELAY="${HOMEBOY_ASSET_RETRY_DELAY:-5}"

platform_target() {
  local os arch
  os=$(uname -s | tr '[:upper:]' '[:lower:]')
  arch=$(uname -m)

  case "${os}-${arch}" in
    linux-x86_64) printf 'x86_64-unknown-linux-gnu' ;;
    linux-aarch64) printf 'aarch64-unknown-linux-gnu' ;;
    darwin-x86_64) printf 'x86_64-apple-darwin' ;;
    darwin-arm64) printf 'aarch64-apple-darwin' ;;
    *)
      echo "::error::Unsupported platform: ${os}-${arch}"
      exit 1
      ;;
  esac
}

release_has_asset() {
  local tag="$1"
  local archive="$2"

  gh api "repos/${REPO}/releases/tags/${tag}" --jq '.assets[].name' 2>/dev/null | grep -Fxq "${archive}"
}

wait_for_asset() {
  local tag="$1"
  local archive="$2"
  local attempt

  for attempt in $(seq 1 "${ASSET_RETRY_ATTEMPTS}"); do
    if release_has_asset "${tag}" "${archive}"; then
      return 0
    fi

    if [ "${attempt}" -lt "${ASSET_RETRY_ATTEMPTS}" ]; then
      echo "Homeboy ${tag#v} asset ${archive} is not available yet; retrying (${attempt}/${ASSET_RETRY_ATTEMPTS})..."
      sleep "${ASSET_RETRY_DELAY}"
    fi
  done

  return 1
}

latest_stable_release_with_asset() {
  local archive="$1"
  local tag asset

  while IFS=$'\t' read -r tag asset; do
    if [ "${asset}" = "${archive}" ]; then
      printf '%s' "${tag}"
      return 0
    fi
  done < <(gh api "repos/${REPO}/releases?per_page=100" --jq '.[] | select(.draft == false and .prerelease == false) | . as $release | $release.assets[]? | [$release.tag_name, .name] | @tsv' 2>/dev/null || true)

  return 1
}

TARGET="$(platform_target)"
ARCHIVE="homeboy-${TARGET}.tar.xz"

if [ "${HOMEBOY_VERSION}" = "latest" ]; then
  TAG=$(latest_stable_release_with_asset "${ARCHIVE}" || true)
  if [ -z "${TAG}" ]; then
    echo "::error::Could not find a stable Homeboy release with asset ${ARCHIVE}"
    exit 1
  fi
else
  TAG="v${HOMEBOY_VERSION#v}"
  if ! wait_for_asset "${TAG}" "${ARCHIVE}"; then
    echo "::error::Homeboy ${TAG#v} asset ${ARCHIVE} was not available; explicit versions do not fall back"
    exit 1
  fi
fi

echo "resolved-version=${TAG#v}" >> "${GITHUB_OUTPUT}"
