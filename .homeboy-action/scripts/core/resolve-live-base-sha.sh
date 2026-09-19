#!/usr/bin/env bash

set -euo pipefail

if [ "${EVENT_NAME:-}" = 'pull_request' ]; then
  [ -n "${REPOSITORY:-}" ] || { echo '::error::REPOSITORY is required for a pull request.'; exit 1; }
  [ -n "${BASE_REF:-}" ] || { echo '::error::BASE_REF is required for a pull request.'; exit 1; }
  encoded_ref="$(jq -rn --arg value "${BASE_REF}" '$value | @uri')"
  sha="$(gh api "repos/${REPOSITORY}/commits/${encoded_ref}" --jq .sha)"
else
  sha="${CURRENT_SHA:-}"
fi

if ! [[ "${sha}" =~ ^[0-9a-f]{40}$ ]]; then
  echo "::error::Could not resolve the live base revision to a commit SHA."
  exit 1
fi

echo "Resolved live base revision ${BASE_REF:-current} -> ${sha}"
echo "sha=${sha}" >> "${GITHUB_OUTPUT}"
