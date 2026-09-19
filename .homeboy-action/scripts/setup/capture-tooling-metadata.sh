#!/usr/bin/env bash

set -euo pipefail

HOMEBOY_CLI_VERSION="$(homeboy --version 2>/dev/null || echo 'unknown')"

# v2: use PORTABLE_EXTENSION (inferred from homeboy.json) as primary,
# fall back to EXTENSION_ID input for backward compat
EXTENSION_ID_EFFECTIVE="${PORTABLE_EXTENSION:-${EXTENSION_ID:-auto}}"

EXTENSION_SOURCE_EFFECTIVE="${EXTENSION_SOURCE:-auto}"
EXTENSION_REVISION="unknown"

if [ -n "${EXTENSION_ID_EFFECTIVE}" ] && [ "${EXTENSION_ID_EFFECTIVE}" != "auto" ]; then
  EXT_DIR="${HOME}/.config/homeboy/extensions/${EXTENSION_ID_EFFECTIVE}"
  if [ -d "${EXT_DIR}/.git" ]; then
    EXTENSION_REVISION="$(git -C "${EXT_DIR}" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
  elif [ -f "${EXT_DIR}/.source-revision" ]; then
    EXTENSION_REVISION="$(tr -d '[:space:]' < "${EXT_DIR}/.source-revision")"
    if [ -z "${EXTENSION_REVISION}" ]; then
      EXTENSION_REVISION="unknown"
    fi
  fi
fi

ACTION_REF_USED="${ACTION_REF:-unknown}"
ACTION_REPOSITORY_USED="${ACTION_REPOSITORY:-unknown}"

echo "HOMEBOY_CLI_VERSION=${HOMEBOY_CLI_VERSION}" >> "${GITHUB_ENV}"
echo "HOMEBOY_EXTENSION_ID=${EXTENSION_ID_EFFECTIVE}" >> "${GITHUB_ENV}"
echo "HOMEBOY_EXTENSION_SOURCE=${EXTENSION_SOURCE_EFFECTIVE}" >> "${GITHUB_ENV}"
echo "HOMEBOY_EXTENSION_REVISION=${EXTENSION_REVISION}" >> "${GITHUB_ENV}"
echo "HOMEBOY_ACTION_REF=${ACTION_REF_USED}" >> "${GITHUB_ENV}"
echo "HOMEBOY_ACTION_REPOSITORY=${ACTION_REPOSITORY_USED}" >> "${GITHUB_ENV}"

# Expose the resolved tooling identity as a step output so workflows can key
# caches/markers on "which release tooling actually ran" — a CI-side fix that
# changes the resolved homeboy binary or extension naturally produces a new
# value, so stale per-SHA state self-heals instead of blocking forever
# (homeboy-action#257). Sanitized to a GitHub cache-key-safe token.
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  TOOLING_IDENTITY="${HOMEBOY_CLI_VERSION}-${EXTENSION_REVISION}"
  TOOLING_IDENTITY="$(printf '%s' "${TOOLING_IDENTITY}" | tr -c 'A-Za-z0-9._-' '-')"
  echo "homeboy-version=${HOMEBOY_CLI_VERSION}" >> "${GITHUB_OUTPUT}"
  echo "tooling-identity=${TOOLING_IDENTITY}" >> "${GITHUB_OUTPUT}"
fi

echo "Tooling metadata captured"
echo "- Homeboy CLI: ${HOMEBOY_CLI_VERSION}"
echo "- Extension: ${EXTENSION_ID_EFFECTIVE} (${EXTENSION_SOURCE_EFFECTIVE})"
echo "- Extension revision: ${EXTENSION_REVISION}"
echo "- Action ref: ${ACTION_REF_USED}"
