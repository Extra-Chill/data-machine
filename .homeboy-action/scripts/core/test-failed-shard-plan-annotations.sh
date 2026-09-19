#!/usr/bin/env bash

# Exercise the candidate shard-plan failure surface: the action records repeated
# lifecycle events while the planner still reports its own terminal check error.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

PROGRESS="${ROOT_DIR}/scripts/core/phase-progress.sh"
LOG="${TMP_DIR}/annotations.log"
PROGRESS_FILE="${TMP_DIR}/phase-progress.jsonl"

run_phase() {
  HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${PROGRESS_FILE}" \
    bash "${PROGRESS}" "$@" >>"${LOG}" 2>&1
}

run_phase start preparation
run_phase end preparation
run_phase start changed_scope_resolution
run_phase end changed_scope_resolution
run_phase start changed_scope_resolution
run_phase end changed_scope_resolution
set +e
run_phase run command_execution -- bash -c 'exit 1'
inventory_exit=$?
TEST_INVENTORY_FILE="${TMP_DIR}/missing-inventory.json" \
TEST_SHARD_PLAN_FILE="${TMP_DIR}/plan.json" \
TEST_SHARD_COUNT=2 \
  bash "${ROOT_DIR}/scripts/core/shard-tests.sh" plan >>"${LOG}" 2>&1
planner_exit=$?
set -e

if [ "${inventory_exit}" -ne 1 ] || [ "${planner_exit}" -ne 1 ]; then
  printf 'FAIL: failed shard-plan fixture did not exercise both terminal failures\n'
  exit 1
fi
if [ "$(grep -c '^::' "${LOG}")" -ne 2 ]; then
  printf 'FAIL: failed shard-plan fixture emitted an unbounded annotation count\n'
  exit 1
fi
if [ "$(grep -c '^::error title=Homeboy phase failed \[command_execution\]::' "${LOG}")" -ne 1 ] \
  || [ "$(grep -c '^::error::Test inventory is missing:' "${LOG}")" -ne 1 ]; then
  printf 'FAIL: failed shard-plan fixture did not retain one terminal annotation per stable identity\n'
  exit 1
fi
if ! jq -se '[.[] | select(.event == "completed") | .phase] == ["preparation", "changed_scope_resolution", "changed_scope_resolution", "command_execution"]' "${PROGRESS_FILE}" >/dev/null; then
  printf 'FAIL: failed shard-plan fixture did not retain complete phase progress artifact\n'
  exit 1
fi

printf 'PASS: failed shard-plan fixture bounds annotations and retains stable identities\n'
