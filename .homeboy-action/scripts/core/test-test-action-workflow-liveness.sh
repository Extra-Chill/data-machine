#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

if ! grep -q 'name: Candidate \${{ matrix.title }}' "${ROOT_DIR}/.github/workflows/ci.yml" \
  || ! grep -q 'commands: \${{ matrix.command }}' "${ROOT_DIR}/.github/workflows/ci.yml" \
  || ! grep -q '^      execution-timeout-seconds:' "${ROOT_DIR}/.github/workflows/ci.yml" \
  || [ "$(grep -c '^          execution-timeout-seconds: \${{ inputs.execution-timeout-seconds }}' "${ROOT_DIR}/.github/workflows/ci.yml")" -ne 4 ] \
  || ! grep -q '^      test-timeout-seconds:' "${ROOT_DIR}/.github/workflows/ci.yml" \
  || [ "$(grep -c '^          test-timeout-seconds: \${{ inputs.test-timeout-seconds }}' "${ROOT_DIR}/.github/workflows/ci.yml")" -ne 4 ] \
  || [ "$(grep -c "HOMEBOY_TEST_TIMEOUT_SECONDS: \${{ inputs.test-timeout-seconds || env.HOMEBOY_TEST_TIMEOUT_SECONDS || '1500' }}" "${ROOT_DIR}/action.yml")" -ne 5 ] \
  || ! grep -q "if: steps.resolve-commands.outputs.has-test == 'true'" "${ROOT_DIR}/action.yml" \
  || ! grep -q '^      cleanup-timeout-seconds:' "${ROOT_DIR}/.github/workflows/ci.yml" \
  || [ "$(grep -c '^          cleanup-timeout-seconds: \${{ inputs.cleanup-timeout-seconds }}' "${ROOT_DIR}/.github/workflows/ci.yml")" -ne 4 ] \
  || ! grep -A2 'name: Fail candidate phase when terminal evidence is not pass' "${ROOT_DIR}/.github/workflows/ci.yml" | grep -q "if: always() && inputs.differential-gating != 'true' && steps.phase.outcome == 'failure'" \
  || ! grep -A8 'name: Validate candidate Test shard terminal provenance' "${ROOT_DIR}/.github/workflows/ci.yml" | grep -q 'if: always()' \
  || grep -A8 'name: Validate candidate Test shard terminal provenance' "${ROOT_DIR}/.github/workflows/ci.yml" | grep -q 'differential-gating'; then
  printf 'FAIL: reusable CI Test job does not pass its exact matrix command to the action\n'
  exit 1
fi

mkdir -p "${TMP_DIR}/bin" "${TMP_DIR}/workspace"
cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash

set -euo pipefail
output=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done

mkdir -p "$(dirname "${output}")"
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{"test_counts":{"failed":0,"passed":1,"total":1}}}' > "${output}"
case "${FAKE_HOMEBOY_MODE:-pass}" in
  descendant)
    sleep 30 &
    printf '%s\n' "$!" > "${FAKE_HOMEBOY_CHILD_PID_FILE}"
    printf 'test output retained\n'
    ;;
  timeout)
    trap '' TERM
    (trap '' TERM; while :; do sleep 1; done) &
    printf '%s\n' "$!" > "${FAKE_HOMEBOY_CHILD_PID_FILE}"
    printf 'test timeout output retained\n'
    while :; do sleep 1; done
    ;;
esac
SH
chmod +x "${TMP_DIR}/bin/homeboy"

cd "${TMP_DIR}/workspace"
git init -q -b main
git config user.email test@example.com
git config user.name test
touch fixture
git add fixture
git commit -qm fixture
git checkout -qb feature

run_test_phase() {
  local mode="$1"
  local output_file="$2"
  local env_file="$3"
  PATH="${TMP_DIR}/bin:${PATH}" \
  GITHUB_ACTION_PATH="${ROOT_DIR}" \
  GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
  GITHUB_OUTPUT="${output_file}" \
  GITHUB_ENV="${env_file}" \
  RESOLVED_COMMANDS='review test' \
  COMPONENT_NAME='fixture' \
  HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 \
  HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 \
  FAKE_HOMEBOY_MODE="${mode}" \
  FAKE_HOMEBOY_CHILD_PID_FILE="${TMP_DIR}/${mode}.pid" \
  bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh"
}

run_test_phase descendant "${TMP_DIR}/primary-output" "${TMP_DIR}/primary-env" >"${TMP_DIR}/primary.log" 2>&1
primary_child="$(<"${TMP_DIR}/descendant.pid")"
if kill -0 "${primary_child}" 2>/dev/null; then
  printf 'FAIL: exact Test command finalization left descendant %s alive\n' "${primary_child}"
  exit 1
fi
if ! grep -q '^results={"review test":"pass"}$' "${TMP_DIR}/primary-output" || ! grep -q 'test output retained' "${TMP_DIR}/primary.log"; then
  printf 'FAIL: exact Test command did not retain output and return its result\n'
  exit 1
fi

set +e
run_test_phase timeout "${TMP_DIR}/timeout-output" "${TMP_DIR}/timeout-env" >"${TMP_DIR}/timeout.log" 2>&1
timeout_exit=$?
set -e
timeout_child="$(<"${TMP_DIR}/timeout.pid")"
if [ "${timeout_exit}" -ne 1 ] || kill -0 "${timeout_child}" 2>/dev/null; then
  printf 'FAIL: TERM-resistant exact Test command was not bounded and reaped\n'
  exit 1
fi
if ! grep -q '^results={"review test":"timeout"}$' "${TMP_DIR}/timeout-output" || ! grep -q 'test timeout output retained' "${TMP_DIR}/timeout.log"; then
  printf 'FAIL: exact Test timeout did not retain output and classify the timeout\n'
  exit 1
fi

set +e
FIRST_RESULTS='' COMMANDS='review test' GITHUB_OUTPUT="${TMP_DIR}/final-output" \
  GITHUB_ACTION_PATH="${ROOT_DIR}" bash "${ROOT_DIR}/scripts/core/select-final-results.sh" >"${TMP_DIR}/finalize.log" 2>&1
select_exit=$?
RESULTS='{"review test":"fail"}' COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' \
  bash "${ROOT_DIR}/scripts/core/enforce-final-status.sh" >>"${TMP_DIR}/finalize.log" 2>&1
enforce_exit=$?
set -e
if [ "${select_exit}" -ne 0 ] || [ "${enforce_exit}" -ne 1 ] || ! grep -q 'marking all expected commands failed' "${TMP_DIR}/finalize.log"; then
  printf 'FAIL: Test action crash path does not fail closed during finalization\n'
  exit 1
fi
printf 'PASS: reusable CI Test workflow covers command liveness, finalization, and crash-safe enforcement\n'
