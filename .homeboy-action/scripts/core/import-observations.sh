#!/usr/bin/env bash

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"

IMPORT_ROOT="${HOMEBOY_OBSERVATION_IMPORT_DIR:-${GITHUB_WORKSPACE:-$(pwd)}/homeboy-observations-import}"

echo ""
echo "--------------------------------------------------"
echo "  Importing Homeboy observations from ${IMPORT_ROOT}"
echo "--------------------------------------------------"
echo ""

if [ ! -d "${IMPORT_ROOT}" ]; then
  echo "::notice::No Homeboy observation artifacts were downloaded for import"
  exit 0
fi

bundle_dirs=()
while IFS= read -r bundle_dir; do
  bundle_dirs+=("${bundle_dir}")
done < <(find "${IMPORT_ROOT}" -name manifest.json -type f -exec dirname {} \; | sort -u)

if [ "${#bundle_dirs[@]}" -eq 0 ]; then
  echo "::notice::Downloaded observation artifacts did not contain Homeboy observation bundles"
  exit 0
fi

for bundle_dir in "${bundle_dirs[@]}"; do
  import_cmd="$(build_observation_import_command "${bundle_dir}")"
  echo "Importing Homeboy observations: ${import_cmd}"

  import_exit=0
  set +e
  eval "${import_cmd}"
  import_exit=$?
  set -e

  if [ "${import_exit}" -ne 0 ]; then
    echo "::warning::${import_cmd} failed with exit code ${import_exit}; continuing without this observation bundle"
  fi
done
