#!/usr/bin/env bash

# Read homeboy.json for component identity (ID, extension, directory).
#
# This runs early (Phase 1) before homeboy is installed, so it only reads
# the minimal fields needed for cache keys and command routing. Runtime
# version detection (PHP, Node) is handled later by detect-runtime-env.sh
# after the homeboy binary is available.
#
# Outputs:
#   PORTABLE_ID         — component id
#   PORTABLE_EXTENSION  — explicit extension input, or first key from extensions object
#   COMPONENT_DIR       — directory containing homeboy.json
#
# All values are written to GITHUB_ENV and GITHUB_OUTPUT for use by subsequent steps.

set -euo pipefail

# ── Resolve config path ──
# When COMPONENT_NAME is set and a homeboy.json exists in that subdirectory,
# read config from there instead of the repo root. This supports multi-component
# repos where each component has its own homeboy.json.

COMPONENT_NAME="${COMPONENT_NAME:-}"
CONFIG_DIR="."

if [ -n "${COMPONENT_NAME}" ] && [ -f "${COMPONENT_NAME}/homeboy.json" ]; then
  CONFIG_DIR="${COMPONENT_NAME}"
  echo "Reading config from component subdirectory: ${CONFIG_DIR}/"
elif [ ! -f "homeboy.json" ]; then
  echo "::error::homeboy.json is required at repository root (or in component subdirectory when component input is set)"
  echo "::error::Create homeboy.json with at least an \"id\" field and re-run CI"
  exit 1
fi

CONFIG_FILE="${CONFIG_DIR}/homeboy.json"

# ── Component ID ──

PORTABLE_ID="$(jq -r '.id // empty' "${CONFIG_FILE}" 2>/dev/null || true)"
if [ -z "${PORTABLE_ID}" ]; then
  echo "::error::${CONFIG_FILE} must include a top-level \"id\" field"
  exit 1
fi

# ── Extension ──
# If the action input specifies an extension, use that single override. Otherwise
# infer the first key from the extensions object for downstream runtime detection.
# Installation itself uses Homeboy core to install every configured extension.

EXTENSION_INPUT="${EXTENSION_INPUT:-}"
if [ -n "${EXTENSION_INPUT}" ]; then
  PORTABLE_EXTENSION="${EXTENSION_INPUT}"
else
  PORTABLE_EXTENSION="$(jq -r '.extensions // {} | keys | first // empty' "${CONFIG_FILE}" 2>/dev/null || true)"
fi

# ── Write outputs ──

echo "PORTABLE_ID=${PORTABLE_ID}" >> "${GITHUB_ENV}"
echo "PORTABLE_EXTENSION=${PORTABLE_EXTENSION}" >> "${GITHUB_ENV}"
echo "COMPONENT_DIR=${CONFIG_DIR}" >> "${GITHUB_ENV}"

echo "portable-id=${PORTABLE_ID}" >> "${GITHUB_OUTPUT}"
echo "portable-extension=${PORTABLE_EXTENSION}" >> "${GITHUB_OUTPUT}"
echo "component-dir=${CONFIG_DIR}" >> "${GITHUB_OUTPUT}"

echo "Config resolved from ${CONFIG_FILE}:"
echo "  id:        ${PORTABLE_ID}"
echo "  extension: ${PORTABLE_EXTENSION:-none}"
echo "  dir:       ${CONFIG_DIR}"
