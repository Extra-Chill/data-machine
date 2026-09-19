#!/usr/bin/env bash

set -euo pipefail

repository="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
run_id="${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
destination="${BINARY_DESTINATION:-.homeboy-bin}"
selected=""
selected_attempt=0

while IFS= read -r name; do
  if [[ "${name}" =~ ^homeboy-candidate-binary-([0-9]+)$ ]]; then
    attempt="${BASH_REMATCH[1]}"
    if [ "${attempt}" -gt "${selected_attempt}" ]; then
      selected="${name}"
      selected_attempt="${attempt}"
    fi
  fi
done < <(gh api "repos/${repository}/actions/runs/${run_id}/artifacts" --paginate --jq '.artifacts[].name')

if [ -z "${selected}" ]; then
  echo "::error::No candidate Homeboy binary artifact exists for run ${run_id}."
  exit 1
fi

rm -rf "${destination}"
mkdir -p "${destination}"
gh run download "${run_id}" --repo "${repository}" --name "${selected}" --dir "${destination}"

binary="${destination}/homeboy"
if [ ! -f "${binary}" ]; then
  echo "::error::Artifact ${selected} did not contain homeboy."
  exit 1
fi

chmod +x "${binary}"
if ! cli_revision="$("${binary}" --version 2>/dev/null)" || [ -z "${cli_revision}" ] || [[ "${cli_revision}" == *$'\n'* ]]; then
  echo "::error::Selected candidate binary from ${selected} did not report a single-line CLI revision with '--version'."
  exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
  digest="$(sha256sum "${binary}" | cut -d' ' -f1)"
else
  digest="$(shasum -a 256 "${binary}" | cut -d' ' -f1)"
fi

printf 'binary-path=%s\nbinary-sha256=%s\ncli-revision=%s\nartifact-name=%s\n' \
  "${binary}" "${digest}" "${cli_revision}" "${selected}" >> "${GITHUB_OUTPUT}"
echo "Selected ${selected} (${digest}; ${cli_revision})."
