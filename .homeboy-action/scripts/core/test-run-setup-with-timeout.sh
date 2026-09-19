#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="${ROOT_DIR}/scripts/core/run-setup-with-timeout.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

run_setup() {
  : > "${TMP_DIR}/github-env"
  HOMEBOY_ACTION_SETUP_TIMEOUT_SECONDS=1 \
  HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 \
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=false \
  GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
  GITHUB_ENV="${TMP_DIR}/github-env" \
  bash "${RUNNER}" "$@"
}

mkdir -p "${TMP_DIR}/workspace"

if [ "$(run_setup 'successful setup' -- bash -c 'printf ready')" != 'ready' ]; then
  printf 'FAIL: successful setup did not preserve output\n'
  exit 1
fi
printf 'PASS: successful setup completes with retained output\n'

set +e
run_setup 'hung child setup' -- bash -c 'sleep 30 & echo $! > "$0"; wait' "${TMP_DIR}/hung-child.pid" >"${TMP_DIR}/hung.log" 2>&1
status=$?
set -e
if [ "${status}" -ne 124 ] || kill -0 "$(<"${TMP_DIR}/hung-child.pid")" 2>/dev/null; then
  printf 'FAIL: hung child setup was not bounded and cleaned up\n'
  exit 1
fi
grep -Fq 'hung child setup timed out during action setup after 1s' "${TMP_DIR}/hung.log" || { printf 'FAIL: hung child lacks actionable evidence\n'; exit 1; }
printf 'PASS: hung child setup times out, reports evidence, and cleans descendants\n'

set +e
run_setup 'stale setup lock' -- bash -c 'mkdir "$0"; while :; do sleep 1; done' "${TMP_DIR}/stale.lock" >"${TMP_DIR}/lock.log" 2>&1
status=$?
set -e
[ "${status}" -eq 124 ] || { printf 'FAIL: stale lock wait was not bounded\n'; exit 1; }
grep -Fq 'stale setup lock exceeded its 1s execution timeout' "${TMP_DIR}/lock.log" || { printf 'FAIL: stale lock lacks timeout evidence\n'; exit 1; }
printf 'PASS: stale lock wait terminates with actionable evidence\n'

set +e
run_setup 'network download' -- bash -c 'sleep 30' >"${TMP_DIR}/network.log" 2>&1
status=$?
set -e
[ "${status}" -eq 124 ] || { printf 'FAIL: network stall was not bounded\n'; exit 1; }
grep -Fq 'network download timed out during action setup after 1s' "${TMP_DIR}/network.log" || { printf 'FAIL: network stall lacks setup evidence\n'; exit 1; }
jq -e '
  .schema == "homeboy/action-setup-result/v1"
  and .phase == "dependency_build_setup"
  and .status == "timeout"
  and .owner == "Homeboy Action setup"
  and .step == "network download"
  and .exit_code == 124
  and .diagnostic == "network download timed out during action setup after 1s"
  and (.replay_command | startswith("bash -c ") and contains("sleep"))
' "${TMP_DIR}/workspace/homeboy-ci-results/setup.json" >/dev/null || { printf 'FAIL: network stall did not persist a typed setup result\n'; exit 1; }
grep -Fqx "HOMEBOY_SETUP_RESULT_FILE=${TMP_DIR}/workspace/homeboy-ci-results/setup.json" "${TMP_DIR}/github-env" || { printf 'FAIL: typed setup result was not exported\n'; exit 1; }
printf 'PASS: network stall terminates with actionable evidence\n'

set +e
run_setup 'cancelled setup' -- bash -c 'exit 130' >"${TMP_DIR}/cancel.log" 2>&1
status=$?
set -e
[ "${status}" -eq 130 ] || { printf 'FAIL: cancellation exit was masked as %s\n' "${status}"; exit 1; }
printf 'PASS: cancellation exit is preserved\n'

set +e
run_setup 'install Homeboy extension' -- bash -c 'printf "source SHA mismatch: expected abc, got def\\n"; exit 1' >"${TMP_DIR}/extension.log" 2>&1
status=$?
set -e
[ "${status}" -eq 1 ] || { printf 'FAIL: extension setup failure exit was masked\n'; exit 1; }
jq -e '
  .status == "failed"
  and .owner == "Homeboy extension setup"
  and .step == "install Homeboy extension"
  and .diagnostic == "source SHA mismatch: expected abc, got def"
' "${TMP_DIR}/workspace/homeboy-ci-results/setup.json" >/dev/null || { printf 'FAIL: extension setup cause was not preserved\n'; exit 1; }
printf 'PASS: extension setup failure preserves its typed owner and diagnostic\n'
