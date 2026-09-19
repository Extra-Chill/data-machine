#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENFORCE_STATUS="${SCRIPT_DIR}/enforce-final-status.sh"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

mkdir -p "${TMPDIR}/observations"
cat > "${TMPDIR}/observations/runs.json" <<'JSON'
[
  {
    "kind": "test",
    "status": "error",
    "metadata_json": {
      "error": "Invalid argument 'structured_sidecar': structured sidecar `test.failures` must be a JSON array for schema v1"
    }
  }
]
JSON

assert_exit() {
  local expected="$1"
  local label="$2"
  shift 2

  local output status
  set +e
  output="$("$@" 2>&1)"
  status=$?
  set -e

  if [ "${status}" -ne "${expected}" ]; then
    printf 'FAIL: %s\nexpected exit: %s\nactual exit:   %s\noutput:        %s\n' "${label}" "${expected}" "${status}" "${output}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_exit 1 "malformed quality results fail closed" \
  env RESULTS='{"review test":"fail"}}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

assert_exit 1 "failing quality results fail" \
  env RESULTS='{"review test":"fail"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' HOMEBOY_OBSERVATIONS_DIR="${TMPDIR}/observations" bash "${ENFORCE_STATUS}"

output="$(env RESULTS='{"review test":"fail"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' HOMEBOY_OBSERVATIONS_DIR="${TMPDIR}/observations" bash "${ENFORCE_STATUS}" 2>&1 || true)"
case "${output}" in
  *"structured sidecar \`test.failures\` must be a JSON array for schema v1"*) printf 'PASS: final status renders observation terminal diagnostics\n' ;;
  *) printf 'FAIL: final status omitted observation terminal diagnostics; got: %s\n' "${output}"; exit 1 ;;
esac

assert_exit 1 "timed out quality results fail with their classification" \
  env RESULTS='{"review test":"timeout"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

assert_exit 1 "missing quality results with expected commands fail closed" \
  env RESULTS='' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

cat > "${TMPDIR}/setup.json" <<'JSON'
{"schema":"homeboy/action-setup-result/v1","phase":"dependency_build_setup","status":"failed","owner":"Homeboy extension setup","step":"install Homeboy extension","exit_code":1,"replay_command":"bash install-extension.sh","diagnostic":"source SHA mismatch"}
JSON
assert_exit 1 "setup failure with not_run commands fails under the setup owner" \
  env RESULTS='{"setup":"fail","review test":"not_run"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' HOMEBOY_SETUP_RESULT_FILE="${TMPDIR}/setup.json" bash "${ENFORCE_STATUS}"
output="$(env RESULTS='{"setup":"fail","review test":"not_run"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' HOMEBOY_SETUP_RESULT_FILE="${TMPDIR}/setup.json" bash "${ENFORCE_STATUS}" 2>&1 || true)"
case "${output}" in
  *"Homeboy setup failed [Homeboy extension setup]"*"source SHA mismatch"*"Reproduce: bash install-extension.sh"*) printf 'PASS: setup enforcement preserves owner, cause, and replay command\n' ;;
  *) printf 'FAIL: setup enforcement omitted actionable setup evidence; got: %s\n' "${output}"; exit 1 ;;
esac

assert_exit 0 "closed PR ignores stale failing quality results" \
  env RESULTS='{"review test":"fail"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='false' bash "${ENFORCE_STATUS}"

assert_exit 0 "closed PR ignores malformed stale results" \
  env RESULTS='{"review test":"fail"}}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='false' bash "${ENFORCE_STATUS}"

assert_exit 0 "merged PR preserves a completed passing audit" \
  env RESULTS='{"audit":"pass"}' COMMANDS='audit' OPERATIONS_RESULTS='' PR_ACTIVE='false' bash "${ENFORCE_STATUS}"

assert_exit 0 "passing quality results pass" \
  env RESULTS='{"review test":"pass"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

assert_exit 0 "baseline red quality results do not fail PR" \
  env RESULTS='{"review test":"baseline_red"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

assert_exit 0 "inconclusive quality results do not fail PR" \
  env RESULTS='{"review lint":"inconclusive"}' COMMANDS='review lint' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

# Required lint/Test evidence cannot be absent; audit remains candidate-only.
assert_exit 1 "no_measurement required Test results fail PR" \
  env RESULTS='{"review test":"no_measurement"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "no_measurement required Lint results fail PR" \
  env RESULTS='{"review lint":"no_measurement"}' COMMANDS='review lint' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "no_measurement suffixed lint results fail PR" \
  env RESULTS='{"lint component":"no_measurement"}' COMMANDS='lint component' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "no_measurement suffixed test results fail PR" \
  env RESULTS='{"test component":"no_measurement"}' COMMANDS='test component' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "no_measurement suffixed review lint results fail PR" \
  env RESULTS='{"review lint component":"no_measurement"}' COMMANDS='review lint component' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "no_measurement suffixed review test results fail PR" \
  env RESULTS='{"review test component":"no_measurement"}' COMMANDS='review test component' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 0 "no_measurement Audit remains candidate-only" \
  env RESULTS='{"review audit":"no_measurement"}' COMMANDS='review audit' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 0 "no_measurement suffixed Audit remains candidate-only" \
  env RESULTS='{"review audit component":"no_measurement"}' COMMANDS='review audit component' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

assert_exit 1 "invalid Test evidence fails final enforcement" \
  env RESULTS='{"review test":"invalid_evidence"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"
assert_exit 1 "incomparable Test evidence fails final enforcement" \
  env RESULTS='{"review test":"no_comparable_evidence"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}"

# ...and it must still say so out loud. A silent blocking verdict is not
# actionable, and a warning would misrepresent this as non-blocking.
set +e
output="$(env RESULTS='{"review test":"no_measurement"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ENFORCE_STATUS}" 2>&1)"
status=$?
set -e
if [ "${status}" -eq 0 ]; then
  printf 'FAIL: no_measurement required Test result unexpectedly passed\n'
  exit 1
fi
case "${output}" in
  *"::error::"*"no measurement"*) printf 'PASS: no_measurement emits a blocking annotation\n' ;;
  *) printf 'FAIL: no_measurement did not annotate; got: %s\n' "${output}"; exit 1 ;;
esac

printf 'All final status enforcement checks passed.\n'

# A cancelled run has no verdict to enforce; exiting non-zero would report the
# cancellation itself as a gate failure.
assert_exit 0 "a cancelled run is not enforced as a failure" \
  env RESULTS='{"review test":"fail"}' COMMANDS='review test' HOMEBOY_RUN_CANCELLED=true PR_ACTIVE=true \
  bash "${ENFORCE_STATUS}"
output="$(env RESULTS='{"review test":"fail"}' COMMANDS='review test' HOMEBOY_RUN_CANCELLED=true PR_ACTIVE=true bash "${ENFORCE_STATUS}" 2>&1 || true)"
grep -Fq 'Run was cancelled before commands completed' <<< "${output}" || { printf 'FAIL: cancellation exit did not say why\n'; exit 1; }
printf 'PASS: cancellation exit explains itself\n'

# An uncancelled run with the same results must still fail, so the guard cannot
# quietly disable enforcement.
assert_exit 1 "an uncancelled failure still fails" \
  env RESULTS='{"review test":"fail"}' COMMANDS='review test' HOMEBOY_RUN_CANCELLED=false PR_ACTIVE=true \
  bash "${ENFORCE_STATUS}"

