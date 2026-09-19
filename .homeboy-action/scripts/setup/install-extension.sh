#!/usr/bin/env bash

set -euo pipefail

EXTENSION_INPUT="${EXTENSION_INPUT:-}"
EXTENSION_REF="${EXTENSION_REF:-}"
COMPONENT_DIR="${COMPONENT_DIR:-.}"

if [[ "${EXTENSION_INPUT}" == *,* ]]; then
  echo "::error::Comma-separated extension input is not supported. Declare multiple extensions in homeboy.json instead."
  exit 1
fi

refresh_extension() {
  local extension_id="$1"

  if [ -z "${extension_id}" ]; then
    return 0
  fi

  # GitHub Actions restores ~/.config/homeboy/extensions from cache. Homeboy's
  # install command preserves an existing extension tree, so remove the cached
  # copy first to guarantee each run consumes the current extension release.
  homeboy extension uninstall "${extension_id}" >/dev/null 2>&1 || true
}

refresh_configured_extensions() {
  local config_file="${COMPONENT_DIR}/homeboy.json"

  if [ ! -f "${config_file}" ]; then
    refresh_extension "${EXTENSION_ID}"
    return 0
  fi

  while IFS= read -r configured_extension_id; do
    refresh_extension "${configured_extension_id}"
  done < <(jq -r '.extensions // {} | keys[]' "${config_file}" 2>/dev/null || true)
}

install_extension() {
  local extension_id="$1"
  local args=(extension install "${EXTENSION_SOURCE}" --id "${extension_id}")

  if [ -n "${EXTENSION_REF}" ]; then
    args+=(--ref "${EXTENSION_REF}")
  fi

  homeboy "${args[@]}"
}

install_configured_extensions_at_ref() {
  local config_file="${COMPONENT_DIR}/homeboy.json"

  if [ ! -f "${config_file}" ]; then
    refresh_extension "${EXTENSION_ID}"
    install_extension "${EXTENSION_ID}"
    return 0
  fi

  while IFS= read -r configured_extension_id; do
    refresh_extension "${configured_extension_id}"
    install_extension "${configured_extension_id}"
  done < <(jq -r '.extensions // {} | keys[]' "${config_file}" 2>/dev/null || true)
}

if [ -n "${EXTENSION_INPUT}" ]; then
  echo "Installing extension override: ${EXTENSION_INPUT} from ${EXTENSION_SOURCE}..."
  refresh_extension "${EXTENSION_INPUT}"
  install_extension "${EXTENSION_INPUT}"
  echo "Extension '${EXTENSION_INPUT}' installed successfully"
elif [ -n "${EXTENSION_REF}" ]; then
  echo "Installing configured extensions from ${EXTENSION_SOURCE} at ${EXTENSION_REF}..."
  install_configured_extensions_at_ref
  echo "Configured extensions installed successfully"
else
  if homeboy extension install-for-component --help >/dev/null 2>&1; then
    echo "Installing extensions configured by ${COMPONENT_DIR}/homeboy.json from ${EXTENSION_SOURCE}..."
    refresh_configured_extensions
    homeboy extension install-for-component --path "${COMPONENT_DIR}" --source "${EXTENSION_SOURCE}"
    echo "Configured extensions installed successfully"
  else
    echo "::warning::Installed Homeboy does not support 'extension install-for-component'; falling back to '${EXTENSION_ID}' only"
    refresh_extension "${EXTENSION_ID}"
    install_extension "${EXTENSION_ID}"
    echo "Extension '${EXTENSION_ID}' installed successfully"
  fi
fi
