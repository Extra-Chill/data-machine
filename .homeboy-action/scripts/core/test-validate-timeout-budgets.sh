#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VALIDATE="${ROOT}/scripts/core/validate-timeout-budgets.sh"

HOMEBOY_TEST_TIMEOUT_SECONDS=1500 HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1800 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=15 bash "${VALIDATE}"
printf 'PASS: default timeout ordering is valid\n'

for values in '1800 1800 15' '1790 1800 15' 'invalid 1800 15'; do
  read -r test_timeout execution_timeout cleanup_timeout <<< "${values}"
  if HOMEBOY_TEST_TIMEOUT_SECONDS="${test_timeout}" HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS="${execution_timeout}" HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS="${cleanup_timeout}" bash "${VALIDATE}" >/dev/null 2>&1; then
    printf 'FAIL: invalid timeout ordering passed: %s\n' "${values}"
    exit 1
  fi
done
printf 'PASS: invalid timeout values and insufficient cleanup margin fail closed\n'
