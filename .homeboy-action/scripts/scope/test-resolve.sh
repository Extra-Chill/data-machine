#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESOLVE_SCRIPT="${SCRIPT_DIR}/resolve.sh"

assert_env_contains() {
  local file="$1"
  local expected="$2"
  local label="$3"

  if grep -qx "${expected}" "${file}"; then
    printf 'PASS: %s\n' "${label}"
    return
  fi

  printf 'FAIL: %s\nexpected env line: %s\nactual:\n' "${label}" "${expected}"
  sed 's/^/  /' "${file}"
  exit 1
}

run_resolve() {
  local env_file output_file
  env_file="$(mktemp)"
  output_file="$(mktemp)"

  GITHUB_ENV="${env_file}" \
    GITHUB_OUTPUT="${output_file}" \
    "$@" \
    bash "${RESOLVE_SCRIPT}" >/dev/null

  printf '%s\n' "${env_file}"
}

env_file="$(run_resolve env GITHUB_EVENT_NAME=push SCOPE_INPUT=auto)"
assert_env_contains "${env_file}" "SCOPE_MODE=full" "auto push uses full scope"

env_file="$(run_resolve env GITHUB_EVENT_NAME=pull_request SCOPE_INPUT=full BASE_SHA=missing)"
assert_env_contains "${env_file}" "SCOPE_MODE=full" "explicit full scope bypasses PR base resolution"

env_file="$(run_resolve env GITHUB_EVENT_NAME=workflow_dispatch SCOPE_INPUT=changed)"
assert_env_contains "${env_file}" "SCOPE_MODE=full" "changed scope without base falls back to full"

env_file="$(run_resolve env GITHUB_EVENT_NAME=push SCOPE_INPUT=unexpected)"
assert_env_contains "${env_file}" "SCOPE_MODE=full" "invalid scope falls back to auto"

base_sha="$(git rev-parse HEAD~1)"
env_file="$(run_resolve env GITHUB_EVENT_NAME=pull_request SCOPE_INPUT=auto BASE_SHA="${base_sha}" PR_HEAD_REPO=Extra-Chill/homeboy-action GITHUB_REPOSITORY=Extra-Chill/homeboy-action)"
assert_env_contains "${env_file}" "SCOPE_MODE=changed" "auto PR uses changed scope"
assert_env_contains "${env_file}" "SCOPE_BASE_REF=${base_sha}" "auto PR records base ref"

# --- scope_flags_for -------------------------------------------------------
#
# `audit` used to share the differential-gating exemption with `test`, so under
# gating it ran unscoped over the whole repository: 12m07s to report 445 findings
# and exit 1, then a 684s baseline rerun that failed identically and downgraded
# the verdict to `baseline_red` (homeboy#11751 W1-2).

assert_flags() {
  local cmd="$1" gating="$2" expected="$3" label="$4" actual
  actual="$(
    SCOPE_MODE=changed SCOPE_BASE_REF=origin/main HOMEBOY_DIFFERENTIAL_GATING="${gating}" \
    bash -c "source '${SCRIPT_DIR}/flags.sh'; scope_flags_for '${cmd}'"
  )"
  if [ "${actual}" != "${expected}" ]; then
    printf 'FAIL: %s (expected %s, got %s)\n' "${label}" "'${expected}'" "'${actual}'"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

assert_flags audit true '--changed-since origin/main' 'audit is scoped under differential gating'
assert_flags audit false '--changed-since origin/main' 'audit is scoped without differential gating'
assert_flags lint true '--changed-since origin/main' 'lint stays scoped under differential gating'
assert_flags refactor true '--changed-since origin/main' 'refactor stays scoped'
assert_flags review true '--changed-since origin/main' 'review stays scoped'

# Homeboy turns this one changed ref into the extension's exact changed-test
# selection. Without the CLI flag, no selection contract is produced and every
# differential Test silently expands to the full suite (homeboy#11751 W1-3).
assert_flags test true '--changed-since origin/main' 'test is scoped under differential gating'
assert_flags test false '--changed-since origin/main' 'test is scoped without differential gating'

# Never-scoped commands must stay unscoped regardless of gating.
assert_flags release true '' 'release is never scoped'
assert_flags deploy true '' 'deploy is never scoped'

# Nothing is scoped when there is no changed scope to apply.
no_scope="$(SCOPE_MODE=full SCOPE_BASE_REF=origin/main bash -c "source '${SCRIPT_DIR}/flags.sh'; scope_flags_for audit")"
[ -z "${no_scope}" ] || { printf 'FAIL: full scope must not emit --changed-since, got %s\n' "${no_scope}"; exit 1; }
missing_base="$(SCOPE_MODE=changed SCOPE_BASE_REF= bash -c "source '${SCRIPT_DIR}/flags.sh'; scope_flags_for audit")"
[ -z "${missing_base}" ] || { printf 'FAIL: absent base ref must not emit --changed-since, got %s\n' "${missing_base}"; exit 1; }
printf 'PASS: no changed scope emits no flag\n'

printf 'All scope resolver checks passed.\n'
