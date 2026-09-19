#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELECT_RESULTS="${SCRIPT_DIR}/select-final-results.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

read_multiline_output() {
  local file="$1"
  local key="$2"
  local marker value line in_value=false

  while IFS= read -r line; do
    if [ "${in_value}" = true ]; then
      if [ "${line}" = "${marker}" ]; then
        printf '%s' "${value%$'\n'}"
        return 0
      fi
      value+="${line}"$'\n'
      continue
    fi

    case "${line}" in
      "${key}<<"*)
        marker="${line#*<<}"
        value=""
        in_value=true
        ;;
    esac
  done < "${file}"

  return 1
}

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

assert_failed_results() {
  local label="$1"
  local input="$2"
  : > "${TMP_DIR}/output"
  FIRST_RESULTS="${input}" COMMANDS='review audit,review test' GITHUB_OUTPUT="${TMP_DIR}/output" \
    GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >/dev/null
  if ! grep -q '"review audit":"fail"' "${TMP_DIR}/output" || ! grep -q '"review test":"fail"' "${TMP_DIR}/output"; then
    printf 'FAIL: %s\n' "${label}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

assert_failed_results "missing current results synthesize failures" ''
assert_failed_results "malformed current results synthesize failures" '{"review test":"fail"}}'
assert_failed_results "incomplete current results synthesize failures" '{"review audit":"pass"}'

cat > "${TMP_DIR}/setup.json" <<'JSON'
{"schema":"homeboy/action-setup-result/v1","phase":"dependency_build_setup","status":"failed","owner":"Homeboy extension setup","step":"install Homeboy extension","exit_code":1,"replay_command":"bash install-extension.sh","diagnostic":"source SHA mismatch"}
JSON
: > "${TMP_DIR}/output"
FIRST_RESULTS='' COMMANDS='review audit,review test' HOMEBOY_SETUP_RESULT_FILE="${TMP_DIR}/setup.json" GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >"${TMP_DIR}/setup-selection.log"
assert_equals '{"review audit":"not_run","review test":"not_run","setup":"fail"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "setup failure preserves ownership and marks commands not_run"
grep -Fq 'Homeboy extension setup failed during install Homeboy extension' "${TMP_DIR}/setup-selection.log" || { printf 'FAIL: setup reconciliation omitted the owning subsystem\n'; exit 1; }

# A run superseded by a newer push writes no setup result and has no verdict.
# Fabricating `fail` for every expected command made that indistinguishable from
# a real failure -- a red gate naming no rule and producing no findings.
: > "${TMP_DIR}/output"
rm -f "${TMP_DIR}/cancel-setup.json"
FIRST_RESULTS='' COMMANDS='review audit,review test' HOMEBOY_RUN_CANCELLED=true \
  HOMEBOY_SETUP_RESULT_FILE="${TMP_DIR}/cancel-setup.json" GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >"${TMP_DIR}/cancelled.log"
assert_equals '{"review audit":"not_run","review test":"not_run"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "a cancelled run marks commands not_run instead of fail"
grep -Fq 'not reporting cancellation as command failure' "${TMP_DIR}/cancelled.log" || { printf 'FAIL: cancelled selection did not say why\n'; exit 1; }

# A cancellation must not mask a setup failure that already wrote its evidence.
: > "${TMP_DIR}/output"
FIRST_RESULTS='' COMMANDS='review audit,review test' HOMEBOY_RUN_CANCELLED=true \
  HOMEBOY_SETUP_RESULT_FILE="${TMP_DIR}/setup.json" GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >/dev/null
assert_equals '{"review audit":"not_run","review test":"not_run","setup":"fail"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "recorded setup failure still wins over cancellation"

: > "${TMP_DIR}/output"
FIRST_RESULTS='{"review test":"pass"}' COMMANDS='review test' GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >/dev/null
assert_equals '{"review test":"pass"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "passing current results round-trip"

: > "${TMP_DIR}/output"
FIRST_RESULTS='{"audit":"pass"}' COMMANDS='audit' GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >/dev/null
assert_equals '{"audit":"pass"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "passing audit result round-trip"

: > "${TMP_DIR}/output"
FIRST_RESULTS='{"review test":"timeout"}' COMMANDS='review test' GITHUB_OUTPUT="${TMP_DIR}/output" \
  GITHUB_ACTION_PATH="$(cd "${SCRIPT_DIR}/../.." && pwd)" bash "${SELECT_RESULTS}" >/dev/null
assert_equals '{"review test":"timeout"}' "$(read_multiline_output "${TMP_DIR}/output" results)" "timeout current results round-trip"

printf 'All final-result selection checks passed.\n'
