#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

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
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":false,"status":"failed","exit_code":1,"data":{"test_counts":{"failed":2,"passed":41,"total":43}}}' > "${output}"
exit 1
SH
chmod +x "${TMP_DIR}/bin/homeboy"

set +e
PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/github-output" \
GITHUB_ENV="${TMP_DIR}/github-env" \
RESOLVED_COMMANDS='review test' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/run.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 1 ]; then
  printf 'FAIL: failed review test exits 1, got %s\n' "${exit_code}"
  exit 1
fi
if ! grep -q '^results={"review test":"fail"}$' "${TMP_DIR}/github-output"; then
  printf 'FAIL: failed review test is recorded as fail\n'
  exit 1
fi
if ! grep -q 'FAILED (exit code 1)' "${TMP_DIR}/run.log"; then
  printf 'FAIL: failed review test reports its exit code\n'
  exit 1
fi

ci_result="${TMP_DIR}/workspace/homeboy-ci-results/review-test.json"
if ! jq -e . "${ci_result}" >/dev/null 2>&1; then
  printf 'FAIL: valid review test result is retained at the durable CI result path\n'
  exit 1
fi

printf 'PASS: failed review test records a current-run failure at its durable result path\n'

for output_mode in missing malformed; do
  mkdir -p "${TMP_DIR}/workspace/homeboy-ci-results"
  printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{"stale":true}}' > "${TMP_DIR}/workspace/homeboy-ci-results/review-test.json"
  cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
output=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done
mkdir -p "$(dirname "${output}")"
if [ "${FAKE_OUTPUT_MODE:-}" = malformed ]; then
  printf '%s\n' 'not json' > "${output}"
fi
exit 0
SH
  chmod +x "${TMP_DIR}/bin/homeboy"
  set +e
  PATH="${TMP_DIR}/bin:${PATH}" GITHUB_ACTION_PATH="${ROOT_DIR}" GITHUB_WORKSPACE="${TMP_DIR}/workspace" GITHUB_OUTPUT="${TMP_DIR}/${output_mode}-output" GITHUB_ENV="${TMP_DIR}/${output_mode}-env" RESOLVED_COMMANDS='review test' COMPONENT_NAME='homeboy-action' FAKE_OUTPUT_MODE="${output_mode}" bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/${output_mode}.log" 2>&1
  exit_code=$?
  set -e
  if [ "${exit_code}" -ne 1 ] || ! grep -q '^results={"review test":"fail"}$' "${TMP_DIR}/${output_mode}-output" || ! grep -q 'did not write valid structured output' "${TMP_DIR}/${output_mode}.log"; then
    printf 'FAIL: zero-exit %s structured output fails closed\n' "${output_mode}"
    exit 1
  fi
  if [ -e "${TMP_DIR}/workspace/homeboy-ci-results/review-test.json" ]; then
    printf 'FAIL: %s output is advertised as a CI result\n' "${output_mode}"
    exit 1
  fi
done
printf 'PASS: stale non-bench results cannot satisfy missing or malformed current output\n'

for envelope_mode in empty wrong-command inconsistent; do
  cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
output=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done
mkdir -p "$(dirname "${output}")"
case "${FAKE_ENVELOPE_MODE}" in
  empty) printf '%s\n' '{}' > "${output}" ;;
  wrong-command) printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"deploy","success":true,"status":"succeeded","exit_code":0,"data":{}}' > "${output}" ;;
  inconsistent) printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":1,"data":{}}' > "${output}" ;;
esac
exit 0
SH
  chmod +x "${TMP_DIR}/bin/homeboy"
  set +e
  PATH="${TMP_DIR}/bin:${PATH}" GITHUB_ACTION_PATH="${ROOT_DIR}" GITHUB_WORKSPACE="${TMP_DIR}/workspace" GITHUB_OUTPUT="${TMP_DIR}/${envelope_mode}-output" GITHUB_ENV="${TMP_DIR}/${envelope_mode}-env" RESOLVED_COMMANDS='review test' COMPONENT_NAME='homeboy-action' FAKE_ENVELOPE_MODE="${envelope_mode}" bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/${envelope_mode}.log" 2>&1
  exit_code=$?
  set -e
  if [ "${exit_code}" -ne 1 ] || ! grep -q '^results={"review test":"fail"}$' "${TMP_DIR}/${envelope_mode}-output"; then
    printf 'FAIL: %s command-result envelope passes\n' "${envelope_mode}"
    exit 1
  fi
done
printf 'PASS: empty, wrong-command, and inconsistent envelopes fail closed\n'

# Regression: real homeboy reports the top-level command (`review`) in the
# envelope, never the full CI command string (`review lint`). Asserting the
# full string turned every passing review gate into a red release run.
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
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{"status":"passed","findings":[]}}' > "${output}"
exit 0
SH
chmod +x "${TMP_DIR}/bin/homeboy"

set +e
PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/root-command-output" \
GITHUB_ENV="${TMP_DIR}/root-command-env" \
RESOLVED_COMMANDS='review lint' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/root-command.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 0 ] || ! grep -q '^results={"review lint":"pass"}$' "${TMP_DIR}/root-command-output"; then
  printf 'FAIL: subcommand gate rejects the top-level command reported by real homeboy\n'
  cat "${TMP_DIR}/root-command.log"
  exit 1
fi
printf 'PASS: subcommand gate accepts the top-level command homeboy actually reports\n'

# Legacy quality aliases are translated to `review` by build_run_command(), so
# their structured result ownership must be validated against that same root.
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
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","operation":"audit","success":true,"status":"succeeded","exit_code":0,"data":{"changed_since":{"contextual_findings":1,"introduced_findings":0},"verdict":"pass"}}' > "${output}"
exit 0
SH
chmod +x "${TMP_DIR}/bin/homeboy"

PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/audit-output" \
GITHUB_ENV="${TMP_DIR}/audit-env" \
RESOLVED_COMMANDS='audit' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/audit.log" 2>&1

if ! grep -q '^results={"audit":"pass"}$' "${TMP_DIR}/audit-output"; then
  printf 'FAIL: passing legacy audit result is not recorded as pass\n'
  cat "${TMP_DIR}/audit.log"
  exit 1
fi
if ! jq -e '.command == "review" and .operation == "audit" and .success == true and .exit_code == 0' "${TMP_DIR}/workspace/homeboy-ci-results/audit.json" >/dev/null; then
  printf 'FAIL: passing audit result is not retained at its durable path\n'
  exit 1
fi
printf 'PASS: passing legacy audit accepts and retains the review result envelope\n'

cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
trap '' TERM
(trap '' TERM; while :; do sleep 1; done) &
while :; do sleep 1; done
SH
chmod +x "${TMP_DIR}/bin/homeboy"

mkdir -p "${TMP_DIR}/workspace/homeboy-ci-results"
printf '%s\n' '{"stale":true}' > "${TMP_DIR}/workspace/homeboy-ci-results/review-test.test-inventory.json"
printf '%s\n' '{"stale":true}' > "${TMP_DIR}/workspace/homeboy-ci-results/review-test.test-outcomes.json"
set +e
PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/timeout-output" \
GITHUB_ENV="${TMP_DIR}/timeout-env" \
RESOLVED_COMMANDS='review test' \
COMPONENT_NAME='homeboy-action' \
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 \
HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/timeout.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 1 ] || ! grep -q '^results={"review test":"timeout"}$' "${TMP_DIR}/timeout-output"; then
  printf 'FAIL: timed out review test is classified for final enforcement\n'
  exit 1
fi
if ! grep -q 'TIMED OUT (exit code 124)' "${TMP_DIR}/timeout.log"; then
  printf 'FAIL: timed out review test does not report its timeout classification\n'
  exit 1
fi
if [ -e "${TMP_DIR}/workspace/homeboy-ci-results/review-test.test-inventory.json" ] || [ -e "${TMP_DIR}/workspace/homeboy-ci-results/review-test.test-outcomes.json" ]; then
  printf 'FAIL: timed out Test retained stale paired sidecars\n'
  exit 1
fi
printf 'PASS: timed out review test preserves actionable timeout classification\n'

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
if [ -e "${output}" ]; then
  printf 'stale bench output was not removed\n' >&2
  exit 99
fi
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"bench","success":true,"status":"succeeded","exit_code":0,"data":{"current":true}}' > "${output}"
SH
chmod +x "${TMP_DIR}/bin/homeboy"

mkdir -p "${TMP_DIR}/workspace/homeboy-ci-results"
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"bench","success":true,"status":"succeeded","exit_code":0,"data":{"stale":true}}' > "${TMP_DIR}/workspace/homeboy-ci-results/bench.json"

PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/bench-output" \
GITHUB_ENV="${TMP_DIR}/bench-env" \
RESOLVED_COMMANDS='bench' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/bench.log" 2>&1

if ! jq -e '.data.current == true' "${TMP_DIR}/workspace/homeboy-ci-results/bench.json" >/dev/null 2>&1; then
  printf 'FAIL: bench result does not replace stale output at its durable result path\n'
  exit 1
fi
printf 'PASS: bench requires current output at its durable result path\n'

mkdir -p "${TMP_DIR}/symlink-workspace" "${TMP_DIR}/outside-results"
printf 'outside sentinel\n' > "${TMP_DIR}/outside-results/sentinel"
ln -s "${TMP_DIR}/outside-results" "${TMP_DIR}/symlink-workspace/homeboy-ci-results"

set +e
PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/symlink-workspace" \
GITHUB_OUTPUT="${TMP_DIR}/symlink-dir-output" \
GITHUB_ENV="${TMP_DIR}/symlink-dir-env" \
RESOLVED_COMMANDS='bench' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/symlink-dir.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 1 ] || ! grep -q 'must be a real directory' "${TMP_DIR}/symlink-dir.log" || ! grep -qx 'outside sentinel' "${TMP_DIR}/outside-results/sentinel"; then
  printf 'FAIL: symlinked CI results directory fails closed without touching its target\n'
  exit 1
fi
printf 'PASS: symlinked CI results directory fails closed without touching its target\n'

mkdir -p "${TMP_DIR}/target-symlink-workspace/homeboy-ci-results"
printf 'outside result sentinel\n' > "${TMP_DIR}/outside-results/bench.json"
ln -s "${TMP_DIR}/outside-results/bench.json" "${TMP_DIR}/target-symlink-workspace/homeboy-ci-results/bench.json"

PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/target-symlink-workspace" \
GITHUB_OUTPUT="${TMP_DIR}/symlink-target-output" \
GITHUB_ENV="${TMP_DIR}/symlink-target-env" \
RESOLVED_COMMANDS='bench' \
COMPONENT_NAME='homeboy-action' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" >"${TMP_DIR}/symlink-target.log" 2>&1

if [ -L "${TMP_DIR}/target-symlink-workspace/homeboy-ci-results/bench.json" ] \
  || ! jq -e '.data.current == true' "${TMP_DIR}/target-symlink-workspace/homeboy-ci-results/bench.json" >/dev/null 2>&1 \
  || ! grep -qx 'outside result sentinel' "${TMP_DIR}/outside-results/bench.json"; then
  printf 'FAIL: symlinked CI result target is replaced without writing outside the workspace\n'
  exit 1
fi
printf 'PASS: symlinked CI result target is replaced without writing outside the workspace\n'
