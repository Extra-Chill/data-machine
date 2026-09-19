#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
export GITHUB_ACTION_PATH="${ROOT}"

source "${ROOT}/scripts/pr/comment/lib.sh"

assert_key() {
  local label="$1"
  local expected="$2"
  shift 2

  env -i \
    PATH="${PATH}" \
    GITHUB_ACTION_PATH="${ROOT}" \
    "$@" \
    bash -c 'source "${GITHUB_ACTION_PATH}/scripts/pr/comment/lib.sh"; derive_section_key' > /tmp/homeboy-section-key-test.out

  local actual
  actual="$(cat /tmp/homeboy-section-key-test.out)"
  rm -f /tmp/homeboy-section-key-test.out

  if [ "${actual}" != "${expected}" ]; then
    printf 'FAIL: %s expected %s got %s\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_key "compound command" "refactor-from-all" COMMANDS="refactor --from all"
assert_key "explicit section key" "audit-lint" COMMANDS="review audit" COMMENT_SECTION_KEY_INPUT="Audit & Lint"
assert_key "job fallback" "homeboy-build-lint-test" COMMANDS="review audit,review lint,review test" GITHUB_JOB="Homeboy Build (Lint & Test)"

printf 'All section key slug checks passed.\n'
