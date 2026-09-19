#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
export GITHUB_ACTION_PATH="${ROOT}"

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL: %s\nmissing: %s\nbody:\n%s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_not_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" == *"${needle}"* ]]; then
    printf 'FAIL: %s\nunexpected: %s\nbody:\n%s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

export HOMEBOY_CLI_VERSION="homeboy 1.2.3"
export HOMEBOY_EXTENSION_ID="wordpress"
export HOMEBOY_EXTENSION_SOURCE="github"
export HOMEBOY_EXTENSION_REVISION="abc1234"
export HOMEBOY_ACTION_REPOSITORY="Extra-Chill/homeboy-action"
export HOMEBOY_ACTION_REF="feature/footer-test"

source "${ROOT}/scripts/pr/comment/sections.sh"

tooling_footer="$(build_tooling_footer)"

assert_contains "${tooling_footer}" '- Homeboy CLI: `homeboy 1.2.3`' "tooling footer includes CLI version"
assert_contains "${tooling_footer}" '- Action: `Extra-Chill/homeboy-action@feature/footer-test`' "tooling footer renders actual action ref"
assert_not_contains "${tooling_footer}" 'Homeboy Action](https://github.com/Extra-Chill/homeboy-action) v1' "tooling footer does not hardcode v1 footer"
assert_not_contains "${tooling_footer}" 'Homeboy Action](https://github.com/Extra-Chill/homeboy-action) v2' "tooling footer does not hardcode v2 footer"
assert_not_contains "${tooling_footer}" '---' "tooling footer has no redundant separator"

printf 'All tooling footer checks passed.\n'
