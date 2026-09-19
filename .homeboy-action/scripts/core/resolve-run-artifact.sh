#!/usr/bin/env bash

set -euo pipefail

prefix="${1:?artifact prefix is required}"
repository="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
run_id="${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
current_attempt="${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}"
[[ "${current_attempt}" =~ ^[1-9][0-9]*$ ]] || { echo "::error::GITHUB_RUN_ATTEMPT must be a positive integer." >&2; exit 1; }

selected=""
selected_attempt=0
selected_count=0
while IFS= read -r name; do
  suffix="${name#"${prefix}-"}"
  [ "${suffix}" != "${name}" ] && [[ "${suffix}" =~ ^[1-9][0-9]*$ ]] || continue
  [ "${suffix}" -le "${current_attempt}" ] || continue
  if [ "${suffix}" -gt "${selected_attempt}" ]; then
    selected="${name}"
    selected_attempt="${suffix}"
    selected_count=1
  elif [ "${suffix}" -eq "${selected_attempt}" ]; then
    selected_count=$((selected_count + 1))
  fi
done < <(gh api "repos/${repository}/actions/runs/${run_id}/artifacts" --paginate --jq '.artifacts[] | select(.expired == false) | .name')

if [ -z "${selected}" ]; then
  echo "::error::No non-expired ${prefix}-N artifact exists at or before run attempt ${current_attempt}." >&2
  exit 1
fi
if [ "${selected_count}" -ne 1 ]; then
  echo "::error::Expected one ${prefix} artifact at attempt ${selected_attempt}; found ${selected_count}." >&2
  exit 1
fi

printf 'artifact-name=%s\nartifact-attempt=%s\n' "${selected}" "${selected_attempt}" >> "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required}"
echo "Selected ${selected} for run attempt ${current_attempt}."
