#!/usr/bin/env bash

# Decide Test execution before a selected scope can consume its child budget.
set -euo pipefail

fail() { echo "::error::$1" >&2; exit 1; }

inventory="${TEST_INVENTORY_FILE:?TEST_INVENTORY_FILE is required}"
shards="${TEST_SCOPE_SHARDS:?TEST_SCOPE_SHARDS is required}"
budget="${TEST_SCOPE_BUDGET_SECONDS:?TEST_SCOPE_BUDGET_SECONDS is required}"
override="${TEST_SCOPE_ALLOW_OVERRIDE:-false}"

[[ "${shards}" =~ ^[1-9][0-9]*$ ]] || fail "test-shards must be a positive integer."
[[ "${budget}" =~ ^[1-9][0-9]*$ ]] || fail "test-timeout-seconds must be a positive integer."
[[ "${override}" == true || "${override}" == false ]] || fail "allow-oversized-test-scope must be true or false."

[ -f "${inventory}" ] || fail "Test inventory is unavailable; refusing to start a Test process without selected-scope evidence."
selected="$(jq -er 'if .schema == "homeboy/test-inventory/v1" and (.tests | type == "array" and length > 0) then .tests | length else error("invalid inventory") end' "${inventory}")" \
  || fail "Test inventory does not satisfy homeboy/test-inventory/v1; refusing to start a Test process without selected-scope evidence."

duration_total="$(jq -r '[.tests[].duration_ms?] | if length == $selected and all(.[]; type == "number" and . > 0) then add else empty end' --argjson selected "${selected}" "${inventory}")"

emit() {
  local shard_count="$1" budget_state="$2"
  printf 'route=shards\nselected-count=%s\nshard-count=%s\nbudget-evidence=%s\n' "${selected}" "${shard_count}" "${budget_state}" >> "${GITHUB_OUTPUT}"
  echo "::notice::Test shard preflight: inventory-tests=${selected}, configured-shards=${shards}, eligible-shards=${shard_count}, budget=${budget}s, budget-evidence=${budget_state}."
}

shard_count="${shards}"
[ "${shard_count}" -le "${selected}" ] || shard_count="${selected}"
emit "${shard_count}" "$([ -n "${duration_total}" ] && printf pending-shard-plan || printf unknown)"

write_budget() {
  local verdict="$1" max_estimate_ms="$2"
  local file="${TEST_SCOPE_BUDGET_FILE:-${TEST_SHARD_PLAN_FILE:-/tmp/homeboy-test-shard-plan}.budget.json}"
  jq -cn --arg schema 'homeboy/test-scope-budget/v1' --arg verdict "${verdict}" --argjson max_estimate_ms "${max_estimate_ms}" --argjson budget_seconds "${budget}" --argjson override "${override}" \
    '{schema:$schema,verdict:$verdict,max_estimate_ms:$max_estimate_ms,budget_seconds:$budget_seconds,override:$override}' > "${file}"
  printf 'budget-verdict=%s\nbudget-max-estimate-ms=%s\nbudget-seconds=%s\nbudget-override=%s\n' "${verdict}" "${max_estimate_ms}" "${budget}" "${override}" >> "${GITHUB_OUTPUT}"
}

verify() {
  local plan="${TEST_SHARD_PLAN_FILE:?TEST_SHARD_PLAN_FILE is required}"
  [ -f "${plan}" ] || fail "Test shard plan is missing; refusing to start a Test process without budget evidence."
  if [ -z "${duration_total}" ]; then
    write_budget unknown null
    echo "::notice::Test scope preflight budget fit is unknown: the inventory has no complete duration model."
    exit 0
  fi
  max_duration="$(jq -er '[.shards[].estimated_duration_ms] | if length > 0 and all(.[]; type == "number" and . > 0) then max else error("invalid shard durations") end' "${plan}")" \
    || fail "Test shard plan has no deterministic duration evidence; refusing to start a Test process."
  if [ "${max_duration}" -gt $((budget * 1000)) ]; then
    if [ "${override}" = true ]; then
      write_budget overridden "${max_duration}"
      echo "::warning::Test scope preflight budget fit was explicitly overridden (estimated process duration=${max_duration}ms; budget=${budget}s)."
      exit 0
    fi
    write_budget exceeds "${max_duration}"
    fail "Test scope preflight refused the shard plan: estimated process duration=${max_duration}ms exceeds budget=${budget}s. Configure more test-shards or set allow-oversized-test-scope: true."
  fi
  write_budget fits "${max_duration}"
  echo "::notice::Test scope preflight proved planned process duration=${max_duration}ms fits budget=${budget}s."
}

case "${1:-route}" in route) ;; verify) verify; exit 0 ;; *) echo "usage: $0 [route|verify]" >&2; exit 2 ;; esac
