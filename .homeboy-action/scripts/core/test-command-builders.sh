#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="$(mktemp -d)"
FAKE_BIN="${TMP_DIR}/bin"
mkdir -p "${FAKE_BIN}"
cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

cat > "${FAKE_BIN}/homeboy" <<'SH'
#!/usr/bin/env bash

if [ "${1:-}" = "--help" ] && [ "${HOMEBOY_FAKE_STALLED_HELP:-false}" = "true" ]; then
  sleep 30 &
  printf '%s\n' "$!" > "${HOMEBOY_FAKE_CHILD_PID_FILE}"
  wait
fi

if [ "${*: -1}" = "--help" ]; then
  printf '  --placement <auto|local|lab>\n'
fi
SH
chmod +x "${FAKE_BIN}/homeboy"
export PATH="${FAKE_BIN}:${PATH}"
source "${SCRIPT_DIR}/lib.sh"

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
  local haystack="$1"
  local needle="$2"
  local label="$3"

  case "${haystack}" in
    *"${needle}"*)
      printf 'PASS: %s\n' "${label}"
      ;;
    *)
      printf 'FAIL: %s\nexpected to contain: %s\nactual:              %s\n' "${label}" "${needle}" "${haystack}"
      exit 1
      ;;
  esac
}

assert_not_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  case "${haystack}" in
    *"${needle}"*)
      printf 'FAIL: %s\nexpected not to contain: %s\nactual:                  %s\n' "${label}" "${needle}" "${haystack}"
      exit 1
      ;;
    *)
      printf 'PASS: %s\n' "${label}"
      ;;
  esac
}

WORKSPACE="/tmp/workspace"
COMPONENT="data-machine"
OUTPUT_JSON="/tmp/workspace/out.json"

# The placement fallback executes Homeboy help commands. Verify a stalled probe
# cannot retain a child or block command construction indefinitely.
child_pid_file="${TMP_DIR}/stalled-help-child.pid"
placement_probe_log="${TMP_DIR}/placement-probe.log"
unset HOMEBOY_ACTION_PLACEMENT_MODE
export HOMEBOY_FAKE_STALLED_HELP=true
export HOMEBOY_FAKE_CHILD_PID_FILE="${child_pid_file}"
export HOMEBOY_ACTION_PLACEMENT_PROBE_TIMEOUT_SECONDS=1
export HOMEBOY_ACTION_PLACEMENT_PROBE_CLEANUP_TIMEOUT_SECONDS=1
homeboy_placement_mode 2>"${placement_probe_log}"
if [ "${HOMEBOY_ACTION_PLACEMENT_MODE}" != "scoped" ]; then
  printf 'FAIL: stalled global placement probe does not recover through scoped placement\n'
  exit 1
fi
if ! grep -q 'placement capability probe exceeded its 1s timeout' "${placement_probe_log}"; then
  printf 'FAIL: stalled placement probe does not report timeout diagnostics\n'
  exit 1
fi
child_pid="$(<"${child_pid_file}")"
if kill -0 "${child_pid}" 2>/dev/null; then
  printf 'FAIL: stalled placement probe leaves child process %s alive\n' "${child_pid}"
  exit 1
fi
printf 'PASS: stalled placement probe is bounded and cleans its child\n'
unset HOMEBOY_ACTION_PLACEMENT_MODE HOMEBOY_FAKE_STALLED_HELP HOMEBOY_FAKE_CHILD_PID_FILE
unset HOMEBOY_ACTION_PLACEMENT_PROBE_TIMEOUT_SECONDS HOMEBOY_ACTION_PLACEMENT_PROBE_CLEANUP_TIMEOUT_SECONDS

# ── Unscoped (full mode) ──
unset GITHUB_ACTIONS SCOPE_MODE SCOPE_BASE_REF EXTRA_ARGS || true
SCOPE_MODE="full"
assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}")" \
  "lint routes through review and includes workspace path"

assert_equals \
  "homeboy --output /tmp/workspace/out.json review lint data-machine --path /tmp/workspace" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "lint routes through review and includes structured output path"

assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace" \
  "$(build_run_command "review lint" "${COMPONENT}" "${WORKSPACE}")" \
  "review lint includes workspace path"

# ── Scoped (changed mode) ──
SCOPE_MODE="changed"
SCOPE_BASE_REF="origin/main"
unset HOMEBOY_DIFFERENTIAL_GATING || true
assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}")" \
  "lint keeps path with changed-since"

assert_equals \
  "homeboy --output /tmp/workspace/out.json review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "lint keeps output path with changed-since"

assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review lint" "${COMPONENT}" "${WORKSPACE}")" \
  "review lint keeps path with changed-since"

GITHUB_ACTIONS="true"
HOMEBOY_ACTION_PLACEMENT_MODE="global"
assert_equals \
  "homeboy --placement local --output /tmp/workspace/out.json review audit data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review audit" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "GitHub Actions initial audit selects local placement"

assert_equals \
  "homeboy --placement local --output /tmp/workspace/out.json review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "GitHub Actions lint selects local placement"

assert_equals \
  "homeboy --placement local review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "test" "${COMPONENT}" "${WORKSPACE}")" \
  "GitHub Actions test selects local placement"

assert_equals \
  "homeboy --placement local --output /tmp/workspace/out.json review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review lint" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "GitHub Actions review lint selects local placement"

assert_equals \
  "homeboy --placement local review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review test" "${COMPONENT}" "${WORKSPACE}")" \
  "GitHub Actions review test selects local placement"

assert_equals \
  "homeboy --placement local --output /tmp/workspace/out.json review build data-machine --path /tmp/workspace" \
  "$(build_run_command "review build" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "GitHub Actions build selects local placement"

HOMEBOY_DIFFERENTIAL_GATING="true"
assert_equals \
  "homeboy --placement local --output /tmp/workspace/out.json review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review test" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "GitHub Actions differential baseline test selects local placement"
unset HOMEBOY_DIFFERENTIAL_GATING

assert_equals \
  "homeboy --placement local review audit data-machine --baseline --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review audit --baseline" "${COMPONENT}" "${WORKSPACE}")" \
  "GitHub Actions baseline update selects local placement"

assert_equals \
  "homeboy --placement local review data-machine --path /tmp/workspace --report=pr-comment --changed-since origin/main" \
  "$(build_review_report_command "${COMPONENT}" "${WORKSPACE}")" \
  "GitHub Actions report retry selects local placement"

unset GITHUB_ACTIONS HOMEBOY_ACTION_PLACEMENT_MODE

assert_equals \
  "homeboy review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "test" "${COMPONENT}" "${WORKSPACE}")" \
  "test keeps path with changed scope"

assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "audit" "${COMPONENT}" "${WORKSPACE}")" \
  "audit keeps path with changed-since"

assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review audit" "${COMPONENT}" "${WORKSPACE}")" \
  "review audit keeps path with changed-since"

assert_equals \
  "homeboy review build data-machine --path /tmp/workspace" \
  "$(build_run_command "build" "${COMPONENT}" "${WORKSPACE}")" \
  "build routes through review"

assert_equals \
  "homeboy review audit data-machine --baseline --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "audit --baseline" "${COMPONENT}" "${WORKSPACE}")" \
  "audit flags route after component through review"

HOMEBOY_DIFFERENTIAL_GATING="true"
# Reverses the assertion added with differential gating in #153 (7e1fd83), which
# held that a full-scope run on both candidate and base would be diffed into a
# clean verdict. Measured on Extra-Chill/homeboy, the premise does not hold: the
# base is red too, so both sides fail and the gate downgrades to `baseline_red`
# -- 32.7 minutes to publish a green check carrying no verdict (#11751 W1-2).
#
# `test` needs the same changed ref so Homeboy can materialize the extension's
# exact changed-test selection instead of silently running the full suite.
assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "audit" "${COMPONENT}" "${WORKSPACE}")" \
  "differential audit is changed-scoped"

assert_equals \
  "homeboy review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "test" "${COMPONENT}" "${WORKSPACE}")" \
  "differential test uses changed scope"

assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "lint" "${COMPONENT}" "${WORKSPACE}")" \
  "differential lint keeps changed scope"

assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review audit" "${COMPONENT}" "${WORKSPACE}")" \
  "differential review audit is changed-scoped"

assert_equals \
  "homeboy review test data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review test" "${COMPONENT}" "${WORKSPACE}")" \
  "differential review test uses changed scope"

assert_equals \
  "homeboy review lint data-machine --path /tmp/workspace --changed-since origin/main" \
  "$(build_run_command "review lint" "${COMPONENT}" "${WORKSPACE}")" \
  "differential review lint keeps changed scope"

unset HOMEBOY_DIFFERENTIAL_GATING

assert_equals \
  "homeboy review data-machine --path /tmp/workspace --report=pr-comment --changed-since origin/main" \
  "$(build_review_report_command "${COMPONENT}" "${WORKSPACE}")" \
  "review report keeps path with changed-since"

assert_equals \
  "review audit,review lint,review test" \
  "$(resolve_baseline_commands "review audit,review lint,review test" "auto")" \
  "baseline auto keeps requested review audit/lint/test commands"

assert_equals \
  "review audit" \
  "$(resolve_baseline_commands "review audit,review lint,review test" "review audit")" \
  "baseline subset uses requested review command shape"

assert_equals \
  "" \
  "$(resolve_baseline_commands "review test" "review audit")" \
  "baseline subset intersects with matrix command"

assert_equals \
  "" \
  "$(resolve_baseline_commands "review audit,review lint,review test" "none")" \
  "baseline none disables differential reruns"

EXTRA_ARGS="--format json"
assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "review audit" "${COMPONENT}" "${WORKSPACE}")" \
  "run command appends extra args"

assert_equals \
  "homeboy review audit data-machine --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "audit" "${COMPONENT}" "${WORKSPACE}")" \
  "bare audit command appends extra args through review"

assert_equals \
  "homeboy --output /tmp/workspace/out.json review audit data-machine --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "review audit" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "run command keeps output path before extra args"

assert_equals \
  "homeboy --output /tmp/workspace/out.json review audit data-machine --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "audit" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "bare audit command keeps output path before extra args"

unset EXTRA_ARGS
BENCH_RIG="main,pr"
BENCH_SCENARIO="pipeline-scale"
BENCH_RUNS="3"
BENCH_ITERATIONS="10"
BENCH_REGRESSION_THRESHOLD="5"
assert_equals \
  "homeboy --output /tmp/workspace/out.json bench data-machine --path /tmp/workspace --rig main,pr --scenario pipeline-scale --runs 3 --iterations 10 --regression-threshold 5" \
  "$(build_run_command "bench" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")" \
  "bench includes first-class benchmark flags"
unset BENCH_RIG BENCH_SCENARIO BENCH_RUNS BENCH_ITERATIONS BENCH_REGRESSION_THRESHOLD

HOMEBOY_SETTINGS_JSON='{"wp_codebox_workloads":[{"id":"ssi-import","run":[{"type":"wp-cli","command":"wp static-site-importer import-theme static-sites/demo/index.html --format=json","parse":"json"}]}],"wp_codebox_blueprint":{"preferredVersions":{"php":"8.3","wp":"latest"}}}'
bench_settings_cmd="$(build_run_command "bench" "${COMPONENT}" "${WORKSPACE}" "${OUTPUT_JSON}")"
assert_contains "${bench_settings_cmd}" "--setting-json wp_codebox_workloads=" "bench forwards WP Codebox workloads as typed settings"
assert_contains "${bench_settings_cmd}" "--setting-json wp_codebox_blueprint=" "bench forwards WP Codebox blueprint as typed settings"
assert_not_contains "${bench_settings_cmd}" "HOMEBOY_SETTINGS_JSON" "bench does not pass raw settings env name as argument"
unset HOMEBOY_SETTINGS_JSON
EXTRA_ARGS="--format json"

assert_equals \
  "homeboy refactor data-machine --all --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "refactor --all" "${COMPONENT}" "${WORKSPACE}")" \
  "refactor keeps path with changed-since"

assert_equals \
  "homeboy refactor data-machine --from lint --path /tmp/workspace --changed-since origin/main --format json" \
  "$(build_run_command "refactor lint" "${COMPONENT}" "${WORKSPACE}")" \
  "refactor lint shorthand uses the component-scoped source flag"

assert_equals \
  "refactor---all" \
  "$(command_output_stem "refactor --all")" \
  "output stem sanitizes spaced refactor command"

assert_equals \
  "refactor---from-audit---write" \
  "$(command_output_stem "refactor --from audit --write")" \
  "output stem sanitizes refactor command"

unset SCOPE_MODE SCOPE_BASE_REF EXTRA_ARGS || true
SCOPE_MODE="full"

assert_equals \
  "homeboy refactor data-machine --all --path /tmp/workspace" \
  "$(build_run_command "refactor --all" "${COMPONENT}" "${WORKSPACE}")" \
  "refactor keeps workspace path"

assert_equals \
  "homeboy review data-machine --path /tmp/workspace --report=pr-comment" \
  "$(build_review_report_command "${COMPONENT}" "${WORKSPACE}")" \
  "review report keeps workspace path"

assert_equals \
  "homeboy runs export --since 24h --output /tmp/workspace/homeboy-observations" \
  "$(build_observation_export_command "24h" "/tmp/workspace/homeboy-observations")" \
  "observation export uses separate output directory"

assert_equals \
  "homeboy runs import /tmp/workspace/homeboy-observations-import/job" \
  "$(build_observation_import_command "/tmp/workspace/homeboy-observations-import/job")" \
  "observation import uses downloaded bundle directory"

# ── Canonicalize: fleet/deploy commands are filtered out ──

assert_equals \
  "review audit,review lint,review test" \
  "$(canonicalize_commands "review audit,review lint,review test,fleet exec my-fleet -- homeboy upgrade")" \
  "canonicalize strips fleet commands"

assert_equals \
  "review audit,review lint,review test" \
  "$(canonicalize_commands "review audit,deploy my-project --all,review lint,review test")" \
  "canonicalize strips deploy commands"

assert_equals \
  "review audit,review lint,review test,refactor --all" \
  "$(canonicalize_commands "deploy --fleet prod data-machine,review audit,review lint,review test,fleet status my-fleet,refactor --all")" \
  "canonicalize strips all operations and preserves order"

assert_equals \
  "review audit,review lint,review test,refactor --all,bench" \
  "$(canonicalize_commands "bench,review audit,review lint,review test,refactor --all")" \
  "canonicalize places bench after quality commands"

assert_equals \
  "review audit,review lint,review test" \
  "$(canonicalize_commands "review test,review audit,review lint")" \
  "canonicalize orders review quality commands"

assert_equals \
  "" \
  "$(canonicalize_commands "fleet exec my-fleet -- homeboy upgrade,deploy my-project --all")" \
  "canonicalize returns empty when only operations commands"

assert_equals \
  "review audit,review lint,review test" \
  "$(canonicalize_commands "release,review audit,review lint,review test")" \
  "canonicalize strips release commands"

assert_equals \
  "" \
  "$(canonicalize_commands "release")" \
  "canonicalize returns empty when only release commands"

printf 'All command builder checks passed.\n'
