#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

inventory() {
  jq -n --argjson count "$1" --argjson duration "${2:-100}" '{schema:"homeboy/test-inventory/v1",runner:"fixture",runner_fingerprint:"runner",workspace_fingerprint:"workspace",inventory_fingerprint:"inventory",tests:[range(0; $count) | {id:("test-" + tostring),duration_ms:$duration}]}' > "${tmp}/inventory.json"
}

run() {
  : > "${tmp}/output"
  TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SCOPE_EFFECTIVE="$1" TEST_SCOPE_CAP="$2" TEST_SCOPE_SHARDS="$3" TEST_SCOPE_BUDGET_SECONDS=1500 TEST_SCOPE_ALLOW_OVERRIDE="$4" GITHUB_OUTPUT="${tmp}/output" \
    bash "${ROOT}/scripts/core/preflight-test-scope-budget.sh"
}

inventory 8
run changed 2 4 false
grep -Fx 'route=shards' "${tmp}/output" >/dev/null || { echo 'FAIL: eligible configured shards were not selected'; exit 1; }
grep -Fx 'shard-count=4' "${tmp}/output" >/dev/null || { echo 'FAIL: configured shard count was not preserved'; exit 1; }

# v1 inventories carry generic test IDs, not changed-file identities or a
# test-to-file map. A file cap must not reject this 8-test, 2-file-looking ID set.
jq '.tests |= map(.id = ("suite::case-" + .id))' "${tmp}/inventory.json" > "${tmp}/generic-ids.json"
mv "${tmp}/generic-ids.json" "${tmp}/inventory.json"
run changed 2 3 false
grep -Fx 'shard-count=3' "${tmp}/output" >/dev/null || { echo 'FAIL: generic test IDs were incorrectly treated as file selections'; exit 1; }

inventory 2
run changed '' 8 false
grep -Fx 'shard-count=2' "${tmp}/output" >/dev/null || { echo 'FAIL: shard count above inventory was not reduced to an eligible count'; exit 1; }
TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SHARD_COUNT=2 TEST_SHARD_PLAN_FILE="${tmp}/small-plan.json" bash "${ROOT}/scripts/core/shard-tests.sh" plan

inventory 8
jq 'del(.tests[0].duration_ms)' "${tmp}/inventory.json" > "${tmp}/without-duration.json"
mv "${tmp}/without-duration.json" "${tmp}/inventory.json"
run changed 8 4 false
grep -Fx 'budget-evidence=unknown' "${tmp}/output" >/dev/null || { echo 'FAIL: durationless inventory must report unknown budget evidence'; exit 1; }
TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SHARD_COUNT=4 TEST_SHARD_PLAN_FILE="${tmp}/durationless-plan.json" bash "${ROOT}/scripts/core/shard-tests.sh" plan
TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SCOPE_EFFECTIVE=changed TEST_SCOPE_CAP=8 TEST_SCOPE_SHARDS=4 TEST_SCOPE_BUDGET_SECONDS=1500 TEST_SCOPE_ALLOW_OVERRIDE=false TEST_SHARD_PLAN_FILE="${tmp}/durationless-plan.json" GITHUB_OUTPUT="${tmp}/output" bash "${ROOT}/scripts/core/preflight-test-scope-budget.sh" verify >"${tmp}/durationless-verify.log" 2>&1
grep -F 'budget fit is unknown' "${tmp}/durationless-verify.log" >/dev/null || { echo 'FAIL: durationless budget verification must remain unknown'; exit 1; }

inventory 2 1000000
run changed 2 2 false
TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/plan.json" TEST_SHARD_COUNT=1 bash "${ROOT}/scripts/core/shard-tests.sh" plan
if TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SCOPE_SHARDS=1 TEST_SCOPE_BUDGET_SECONDS=1500 TEST_SCOPE_ALLOW_OVERRIDE=false TEST_SHARD_PLAN_FILE="${tmp}/plan.json" TEST_SCOPE_BUDGET_FILE="${tmp}/budget.json" GITHUB_OUTPUT="${tmp}/output" bash "${ROOT}/scripts/core/preflight-test-scope-budget.sh" verify >"${tmp}/budget-over.log" 2>&1; then
  echo 'FAIL: over-budget duration plan must fail'; exit 1
fi
grep -F 'exceeds budget=1500s' "${tmp}/budget-over.log" >/dev/null || { echo 'FAIL: over-budget plan diagnostic is incomplete'; exit 1; }
jq -e '.verdict == "exceeds" and .max_estimate_ms == 2000000 and .budget_seconds == 1500 and .override == false' "${tmp}/budget.json" >/dev/null || { echo 'FAIL: failed budget verdict was not persisted'; exit 1; }
TEST_INVENTORY_FILE="${tmp}/inventory.json" TEST_SCOPE_SHARDS=1 TEST_SCOPE_BUDGET_SECONDS=1500 TEST_SCOPE_ALLOW_OVERRIDE=true TEST_SHARD_PLAN_FILE="${tmp}/plan.json" TEST_SCOPE_BUDGET_FILE="${tmp}/budget.json" GITHUB_OUTPUT="${tmp}/output" bash "${ROOT}/scripts/core/preflight-test-scope-budget.sh" verify >"${tmp}/budget-override.log" 2>&1
grep -F 'budget fit was explicitly overridden' "${tmp}/budget-override.log" >/dev/null || { echo 'FAIL: over-budget override was not reported'; exit 1; }
jq -e '.verdict == "overridden" and .override == true' "${tmp}/budget.json" >/dev/null || { echo 'FAIL: override verdict was not persisted'; exit 1; }
TEST_SHARD_PLAN_FILE="${tmp}/plan.json" TEST_SCOPE_BUDGET_FILE="${tmp}/budget.json" bash "${ROOT}/scripts/core/shard-tests.sh" attach-budget
jq -e '.budget.verdict == "overridden" and (.plan_digest | type == "string" and length > 0)' "${tmp}/plan.json" >/dev/null || { echo 'FAIL: budget verdict was not attached to the immutable shard plan'; exit 1; }

rm "${tmp}/inventory.json"
if run changed 2 1 false >"${tmp}/missing.log" 2>&1; then
  echo 'FAIL: unavailable inventory must fail closed'; exit 1
fi
grep -F 'inventory is unavailable' "${tmp}/missing.log" >/dev/null || { echo 'FAIL: unavailable inventory diagnostic is incomplete'; exit 1; }

inventory 8
run full '' 4 false
grep -Fx 'route=shards' "${tmp}/output" >/dev/null || { echo 'FAIL: explicit configured full scope should retain shards'; exit 1; }
printf 'PASS: Test shard preflight preserves generic inventory and durable budget evidence\n'
