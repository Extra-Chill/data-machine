#!/usr/bin/env bash

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"

OBSERVATION_WINDOW="${HOMEBOY_OBSERVATION_WINDOW:-24h}"
OBSERVATION_DIR="${GITHUB_WORKSPACE:-$(pwd)}/homeboy-observations"
EXPORT_CMD="$(build_observation_export_command "${OBSERVATION_WINDOW}" "${OBSERVATION_DIR}")"

echo "HOMEBOY_OBSERVATIONS_DIR=${OBSERVATION_DIR}" >> "${GITHUB_ENV}"

echo ""
echo "--------------------------------------------------"
echo "  Exporting Homeboy observations: ${EXPORT_CMD}"
echo "--------------------------------------------------"
echo ""

EXPORT_EXIT=0
set +e
eval "${EXPORT_CMD}"
EXPORT_EXIT=$?
set -e

if [ "${EXPORT_EXIT}" -ne 0 ]; then
  echo "::warning::homeboy runs export failed with exit code ${EXPORT_EXIT}; continuing without observation artifact"
  exit 0
fi

if [ -d "${OBSERVATION_DIR}" ] && [ -n "$(find "${OBSERVATION_DIR}" -mindepth 1 -print -quit 2>/dev/null)" ]; then
  echo "::notice::Homeboy observations exported to ${OBSERVATION_DIR}"
else
  echo "::notice::No Homeboy observations found for the ${OBSERVATION_WINDOW} export window"
fi
