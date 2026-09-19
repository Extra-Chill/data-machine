#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ACTION="${ROOT}/action.yml"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"

for input in autofix autofix-mode autofix-open-pr autofix-max-commits autofix-commands autofix-label; do
  if grep -q -- "${input}" "${ACTION}" "${WORKFLOW}"; then
    printf 'FAIL: removed input remains publicly exposed: %s\n' "${input}" >&2
    exit 1
  fi
done

if compgen -G "${ROOT}/scripts/autofix/*" > /dev/null; then
  printf 'FAIL: generic autofix script family remains\n' >&2
  exit 1
fi

if grep -R -q --exclude='test-no-autofix-mutation-paths.sh' --exclude-dir='.git' \
  'scripts/autofix\|AUTOFIX_\|autofix_[a-z_]*(\|build_autofix_pr_body' \
  "${ROOT}/action.yml" "${ROOT}/.github" "${ROOT}/scripts"; then
  printf 'FAIL: generic autofix mutation machinery remains\n' >&2
  exit 1
fi

printf 'No autofix mutation paths are exposed by the action or reusable workflow.\n'
