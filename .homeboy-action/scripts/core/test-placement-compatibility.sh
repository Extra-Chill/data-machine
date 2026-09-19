#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FAKE_BIN="$(mktemp -d)"
TMPDIR="$(mktemp -d)"
cleanup() {
  rm -f "${FAKE_BIN}/homeboy"
  rmdir "${FAKE_BIN}" 2>/dev/null || true
  rm -f "${TMPDIR}/github-env-scoped" "${TMPDIR}/github-env-global" "${TMPDIR}/github-env-legacy" "${TMPDIR}/github-env" "${TMPDIR}/github-output"
  rmdir "${TMPDIR}/homeboy-ci-results" 2>/dev/null || true
  rmdir "${TMPDIR}" 2>/dev/null || true
}
trap cleanup EXIT

assert_equals() {
  local expected="$1" actual="$2" label="$3"
  if [ "${expected}" != "${actual}" ]; then
    printf 'FAIL: %s\nexpected: %s\nactual:   %s\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

assert_contains() {
  local value="$1" expected="$2" label="$3"
  case "${value}" in
    *"${expected}"*) printf 'PASS: %s\n' "${label}" ;;
    *)
      printf 'FAIL: %s\nmissing: %s\nactual: %s\n' "${label}" "${expected}" "${value}"
      exit 1
      ;;
  esac
}

assert_not_contains() {
  local value="$1" unexpected="$2" label="$3"
  case "${value}" in
    *"${unexpected}"*)
      printf 'FAIL: %s\nunexpected: %s\nactual: %s\n' "${label}" "${unexpected}" "${value}"
      exit 1
      ;;
    *) printf 'PASS: %s\n' "${label}" ;;
  esac
}

cat > "${FAKE_BIN}/homeboy" <<'SH'
#!/usr/bin/env bash
if [ "${*: -1}" = "--help" ]; then
  printf 'help\n' >> "${HOMEBOY_HELP_LOG}"
  if [ "${HOMEBOY_FAKE_GENERATION}" = "global" ] && [ "$#" -eq 1 ]; then
    printf '  --placement <auto|local|lab>\n'
  fi
  if [ "${HOMEBOY_FAKE_GENERATION}" = "scoped" ]; then
    case "$*" in
      'review audit --help'|'review lint --help'|'review test --help'|'review --help')
        printf '  --placement <auto|local|lab>\n'
        ;;
    esac
  fi
  exit 0
fi

printf '%s\n' "$*" >> "${HOMEBOY_CALL_LOG}"
output=""
while [ "$#" -gt 0 ]; do
  if [ "$1" = "--output" ]; then
    output="$2"
    shift 2
    continue
  fi
  shift
done
  [ -z "${output}" ] || printf '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{}}\n' > "${output}"
SH
chmod +x "${FAKE_BIN}/homeboy"

export PATH="${FAKE_BIN}:${PATH}"
export GITHUB_ACTIONS=true
export SCOPE_MODE=full
export SCOPE_BASE_REF=
unset HOMEBOY_SETTINGS_JSON
export HOMEBOY_HELP_LOG="${TMPDIR}/help.log"
export HOMEBOY_CALL_LOG="${TMPDIR}/calls.log"
source "${ROOT}/scripts/core/lib.sh"

test_generation() {
  local generation="$1" run_command="$2" test_command="$3" build_command="$4" bench_command="$5" refactor_command="$6" report_command="$7" help_calls="$8"
  : > "${HOMEBOY_HELP_LOG}"
  : > "${HOMEBOY_CALL_LOG}"
  export HOMEBOY_FAKE_GENERATION="${generation}"
  unset HOMEBOY_ACTION_PLACEMENT_MODE

  export GITHUB_ENV="${TMPDIR}/github-env-${generation}"
  : > "${GITHUB_ENV}"
  bash "${ROOT}/scripts/setup/detect-homeboy-placement.sh"
  IFS='=' read -r _ HOMEBOY_ACTION_PLACEMENT_MODE < "${GITHUB_ENV}"
  export HOMEBOY_ACTION_PLACEMENT_MODE

  assert_equals "${run_command}" "$(build_run_command 'review lint' component /tmp/workspace /tmp/out.json)" "${generation} review builder preserves output ordering"
  assert_equals "${test_command}" "$(build_run_command 'review test' component /tmp/workspace /tmp/out.json)" "${generation} review test respects placement support"
  assert_equals "${build_command}" "$(build_run_command 'review build' component /tmp/workspace /tmp/out.json)" "${generation} review build respects placement support"
  assert_equals "${bench_command}" "$(build_run_command bench component /tmp/workspace /tmp/out.json)" "${generation} bench builder"
  assert_equals "${refactor_command}" "$(build_run_command 'refactor --all' component /tmp/workspace)" "${generation} refactor builder"
  assert_equals "${report_command}" "$(build_review_report_command component /tmp/workspace)" "${generation} report builder"
  assert_equals "${help_calls}" "$(wc -l < "${HOMEBOY_HELP_LOG}" | xargs)" "${generation} capability probes"
}

test_generation scoped 'homeboy --output /tmp/out.json review lint --placement local component --path /tmp/workspace' 'homeboy --output /tmp/out.json review test --placement local component --path /tmp/workspace' 'homeboy --output /tmp/out.json review build component --path /tmp/workspace' 'homeboy --output /tmp/out.json bench component --path /tmp/workspace' 'homeboy refactor component --all --path /tmp/workspace' 'homeboy review --placement local component --path /tmp/workspace --report=pr-comment' 6
test_generation global 'homeboy --placement local --output /tmp/out.json review lint component --path /tmp/workspace' 'homeboy --placement local --output /tmp/out.json review test component --path /tmp/workspace' 'homeboy --placement local --output /tmp/out.json review build component --path /tmp/workspace' 'homeboy --placement local --output /tmp/out.json bench component --path /tmp/workspace' 'homeboy --placement local refactor component --all --path /tmp/workspace' 'homeboy --placement local review component --path /tmp/workspace --report=pr-comment' 1
test_generation legacy 'homeboy --force-hot --allow-local-hot --output /tmp/out.json review lint component --path /tmp/workspace' 'homeboy --force-hot --allow-local-hot --output /tmp/out.json review test component --path /tmp/workspace' 'homeboy --force-hot --allow-local-hot --output /tmp/out.json review build component --path /tmp/workspace' 'homeboy --force-hot --allow-local-hot --output /tmp/out.json bench component --path /tmp/workspace' 'homeboy --force-hot --allow-local-hot refactor component --all --path /tmp/workspace' 'homeboy --force-hot --allow-local-hot review component --path /tmp/workspace --report=pr-comment' 2

# Exercise the action execution script with a scoped-placement binary.
: > "${HOMEBOY_HELP_LOG}"
: > "${HOMEBOY_CALL_LOG}"
export HOMEBOY_FAKE_GENERATION=scoped
unset HOMEBOY_ACTION_PLACEMENT_MODE
export GITHUB_ACTION_PATH="${ROOT}"
export GITHUB_WORKSPACE="${TMPDIR}"
export GITHUB_OUTPUT="${TMPDIR}/github-output"
export GITHUB_ENV="${TMPDIR}/github-env"
export RESOLVED_COMMANDS='review audit'
export COMPONENT_NAME=component
bash "${ROOT}/scripts/setup/detect-homeboy-placement.sh"
IFS='=' read -r _ HOMEBOY_ACTION_PLACEMENT_MODE < "${GITHUB_ENV}"
export HOMEBOY_ACTION_PLACEMENT_MODE
bash "${ROOT}/scripts/core/run-homeboy-commands.sh"
assert_contains "$(< "${HOMEBOY_CALL_LOG}")" 'review audit --placement local component' 'scoped binary runs through action command execution with local placement'
assert_not_contains "$(< "${HOMEBOY_CALL_LOG}")" '--force-hot' 'scoped binary does not receive removed hot flags'
assert_equals 3 "$(wc -l < "${HOMEBOY_HELP_LOG}" | xargs)" 'scoped execution probes global, mode, and exact command help'

printf 'All placement compatibility checks passed.\n'
