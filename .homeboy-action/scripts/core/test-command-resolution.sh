#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESOLVE_COMMANDS="${SCRIPT_DIR}/resolve-commands.sh"
ENFORCE_STATUS="${SCRIPT_DIR}/enforce-final-status.sh"

assert_equals() {
  local expected="$1"
  local actual="$2"
  local label="$3"

  if [ "${expected}" != "${actual}" ]; then
    printf 'FAIL: %s\nexpected: %s\nactual:   %s\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_contains() {
  local needle="$1"
  local haystack="$2"
  local label="$3"

  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL: %s\nmissing: %s\noutput:  %s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

make_temp_file() {
  mktemp "${TMPDIR:-/tmp}/homeboy-action-test.XXXXXX"
}

get_output() {
  local file="$1"
  local key="$2"

  grep -E "^${key}=" "${file}" | tail -1 | cut -d= -f2-
}

run_resolve() {
  local commands="$1"
  local context="${2:-manual}"
  local output_file env_file

  output_file="$(make_temp_file)"
  env_file="$(make_temp_file)"

  GITHUB_OUTPUT="${output_file}" \
    GITHUB_ENV="${env_file}" \
    COMMANDS_INPUT="${commands}" \
    SCOPE_CONTEXT="${context}" \
    bash "${RESOLVE_COMMANDS}" >/dev/null

  printf '%s\n' "${output_file}"
}

release_only_output="$(run_resolve "release")"
assert_equals "" "$(get_output "${release_only_output}" "resolved-commands")" "release-only has no quality commands"
assert_equals "release" "$(get_output "${release_only_output}" "release-commands")" "release-only populates release bucket"
assert_equals "" "$(get_output "${release_only_output}" "operations-commands")" "release-only has no operations commands"

mixed_output="$(run_resolve "review audit,release")"
assert_equals "review audit" "$(get_output "${mixed_output}" "resolved-commands")" "mixed review audit/release keeps audit in quality bucket"
assert_equals "release" "$(get_output "${mixed_output}" "release-commands")" "mixed audit/release keeps release in release bucket"

quality_output="$(run_resolve "review audit,review lint,review test")"
assert_equals "review audit,review lint,review test" "$(get_output "${quality_output}" "resolved-commands")" "normal review quality commands stay in quality bucket"
assert_equals "" "$(get_output "${quality_output}" "release-commands")" "normal quality commands have no release bucket"
assert_equals "" "$(get_output "${quality_output}" "operations-commands")" "normal quality commands have no operations bucket"

bench_output="$(run_resolve "bench")"
assert_equals "bench" "$(get_output "${bench_output}" "resolved-commands")" "bench enters structured command bucket"
assert_equals "" "$(get_output "${bench_output}" "release-commands")" "bench has no release bucket"
assert_equals "" "$(get_output "${bench_output}" "operations-commands")" "bench has no operations bucket"

cron_output="$(run_resolve "" "cron")"
assert_equals "" "$(get_output "${cron_output}" "resolved-commands")" "cron default has no quality commands"
assert_equals "release" "$(get_output "${cron_output}" "release-commands")" "cron default populates release bucket"

prepare_output="$(HOMEBOY_PREPARE_ONLY=true run_resolve "review test")"
assert_equals "" "$(get_output "${prepare_output}" "resolved-commands")" "prepare-only suppresses quality commands"
assert_equals "" "$(get_output "${prepare_output}" "release-commands")" "prepare-only suppresses release commands"

review_test_output="$(run_resolve "review test")"
assert_equals "true" "$(get_output "${review_test_output}" "has-test")" "review test enables the test budget"
bare_test_output="$(run_resolve "test")"
assert_equals "true" "$(get_output "${bare_test_output}" "has-test")" "bare test enables the test budget"
contest_output="$(run_resolve "contest,review latest")"
assert_equals "false" "$(get_output "${contest_output}" "has-test")" "unrelated commands containing test do not enable the test budget"

enforce_output="$(RESULTS='{}' COMMANDS='' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}")"
assert_contains "No quality gate commands to enforce" "${enforce_output}" "release-only final status skips quality enforcement"

printf 'All command resolution checks passed.\n'
