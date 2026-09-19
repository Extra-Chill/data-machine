#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL: %s\nmissing: %s\n' "${label}" "${needle}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_not_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" == *"${needle}"* ]]; then
    printf 'FAIL: %s\nunexpected: %s\n' "${label}" "${needle}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

action_yml="$(python3 - "${ROOT}/action.yml" <<'PY'
import sys

text = open(sys.argv[1], encoding="utf-8").read()
start = text.find("    - name: Post PR comment")
if start < 0:
    raise SystemExit("FAIL: action.yml is missing the Post PR comment action section")
end = text.find("    # ── Phase 7: Auto-issue filing", start)
if end < 0:
    raise SystemExit("FAIL: action.yml is missing the Auto-issue filing section after Post PR comment")
print(text[start:end])
PY
)"
post_script="$(cat "${ROOT}/scripts/pr/post-pr-comment.sh")"

assert_contains "${action_yml}" 'GH_TOKEN: ${{ inputs.app-token }}' "PR comment uses app token"
assert_not_contains "${action_yml}" 'GH_TOKEN: ${{ inputs.app-token || github.token }}' "PR comment does not fall back to github token"
assert_contains "${action_yml}" "inputs.pr-comment != 'false'" "PR comment can be disabled explicitly"
assert_contains "${post_script}" 'Refusing to post as github-actions[bot]' "missing token warning explains bot identity"

printf 'PR comment app-token checks passed.\n'
