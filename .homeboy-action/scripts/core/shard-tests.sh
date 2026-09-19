#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/lib.sh"

fail() { echo "::error::$1" >&2; exit 1; }
digest() { printf '%s' "$1" | shasum -a 256 | awk '{print $1}'; }

# Partitioning is longest-processing-time bin packing over `duration_ms`.
#
# In production every test weighs the same. `duration_ms` is OPTIONAL in
# homeboy/test-inventory/v1 and no shipped producer emits it -- the Rust
# extension's `test-shard-inventory.py` builds its inventory from
# `cargo nextest list`, which enumerates tests without running them and so has no
# timings to report. Nothing sets TEST_SHARD_UNKNOWN_DURATION_MS either, so every
# test takes the 60000 default and LPT degenerates to equal-count partitioning.
#
# That is fine, and measuring it is why this comment exists rather than a fix.
# Across recent Extra-Chill/homeboy runs the four shards average 9.0-9.3 min --
# a 3% spread, and that still included per-shard compilation. Real timings could
# not recover more than a rounding error, so feeding them in is not worth the
# artifact plumbing it would take to carry durations between runs.
#
# The LPT path stays because it is correct and already covered: an inventory that
# does carry durations is balanced by weight, so a future producer can supply
# them without a rewrite. See homeboy#11751 W1-7.
plan() {
  local inventory="${TEST_INVENTORY_FILE:?TEST_INVENTORY_FILE is required}"
  local output="${TEST_SHARD_PLAN_FILE:?TEST_SHARD_PLAN_FILE is required}"
  local count="${TEST_SHARD_COUNT:?TEST_SHARD_COUNT is required}"
  local unknown="${TEST_SHARD_UNKNOWN_DURATION_MS:-60000}"
  [[ "${count}" =~ ^[1-9][0-9]*$ && "${unknown}" =~ ^[1-9][0-9]*$ ]] || fail "Test shard count and unknown duration must be positive integers."
  [ -f "${inventory}" ] || fail "Test inventory is missing: ${inventory}"
  jq -e '
    .schema == "homeboy/test-inventory/v1"
    and (.runner | type == "string" and length > 0)
    and (.runner_fingerprint | type == "string" and length > 0)
    and (.workspace_fingerprint | type == "string" and length > 0)
    and (.inventory_fingerprint | type == "string" and length > 0)
    and (.tests | type == "array" and length > 0)
    and all(.tests[]; (.id | type == "string" and length > 0) and ((.duration_ms? == null) or (.duration_ms | type == "number" and . > 0)))
    and ([.tests[].id] | length == (unique | length))
  ' "${inventory}" >/dev/null || fail "Test inventory must satisfy homeboy/test-inventory/v1."
  [ "${count}" -le "$(jq '.tests | length' "${inventory}")" ] || fail "Test shard count exceeds the test inventory; empty shards are not allowed."

  local canonical inventory_digest plan_digest shard_id shard_canonical shard_fingerprint
  canonical="$(jq -cS '{schema,runner,runner_fingerprint,workspace_fingerprint,inventory_fingerprint,tests:(.tests | sort_by(.id))}' "${inventory}")"
  inventory_digest="$(digest "${canonical}")"
  printf '%s' "${canonical}" | jq -cn --slurpfile inventory /dev/stdin --arg inventory_digest "${inventory_digest}" --argjson count "${count}" --argjson unknown "${unknown}" '
    [range(0; $count) | {index:., duration_ms:0, tests:[]}] as $empty
    | reduce ($inventory[0].tests | map(. + {weight:(.duration_ms // $unknown)}) | sort_by(-.weight, .id))[] as $test
        ($empty; (min_by([.duration_ms, .index]).index) as $index | .[$index].duration_ms += $test.weight | .[$index].tests += [$test.id])
    | {schema:"homeboy/test-shard-plan/v1", inventory_digest:$inventory_digest, inventory_fingerprint:$inventory[0].inventory_fingerprint,
       shards:(map({schema:"homeboy/test-shard-manifest/v1", id:("shard-" + ((.index + 1)|tostring)), runner:$inventory[0].runner,
         runner_fingerprint:$inventory[0].runner_fingerprint, workspace_fingerprint:$inventory[0].workspace_fingerprint,
         inventory_fingerprint:$inventory[0].inventory_fingerprint, tests:.tests, estimated_duration_ms:.duration_ms}))}
  ' > "${output}.tmp"
  while IFS= read -r shard_id; do
    shard_canonical="$(jq -cS --arg id "${shard_id}" --slurpfile plan "${output}.tmp" '
      ($plan[0].shards[] | select(.id == $id).tests | reduce .[] as $test_id ({}; .[$test_id] = true)) as $selected
      | {schema,runner,runner_fingerprint,workspace_fingerprint,tests:(.tests | map(select($selected[.id])) | sort_by(.id))}
    ' "${inventory}")"
    shard_fingerprint="$(digest "${shard_canonical}")"
    jq --arg id "${shard_id}" --arg fingerprint "${shard_fingerprint}" '
      (.shards[] | select(.id == $id).inventory_fingerprint) = $fingerprint
    ' "${output}.tmp" > "${output}.next"
    mv "${output}.next" "${output}.tmp"
  done < <(jq -r '.shards[].id' "${output}.tmp")
  plan_digest="$(digest "$(jq -cS . "${output}.tmp")")"
  jq --arg digest "${plan_digest}" '. + {plan_digest:$digest}' "${output}.tmp" > "${output}"
  rm -f "${output}.tmp"
}

aggregate() (
  local root="${TEST_SHARD_ARTIFACT_ROOT:?TEST_SHARD_ARTIFACT_ROOT is required}"
  local plan="${TEST_SHARD_PLAN_FILE:?TEST_SHARD_PLAN_FILE is required}"
  local inventory="${TEST_INVENTORY_FILE:?TEST_INVENTORY_FILE is required}"
  local phase="${TEST_SHARD_PHASE:?TEST_SHARD_PHASE is required}"
  local command="${TEST_SHARD_COMMAND:?TEST_SHARD_COMMAND is required}"
  local output="${TEST_SHARD_OUTPUT_DIR:?TEST_SHARD_OUTPUT_DIR is required}"
  local attempt="${RUN_ATTEMPT:?RUN_ATTEMPT is required}"
  local inventory_digest plan_digest canonical
  inventory_digest="$(jq -r .inventory_digest "${plan}")"
  plan_digest="$(jq -r .plan_digest "${plan}")"
  canonical="$(jq -cS '{schema,runner,runner_fingerprint,workspace_fingerprint,inventory_fingerprint,tests:(.tests | sort_by(.id))}' "${inventory}")"
  [ "$(digest "${canonical}")" = "${inventory_digest}" ] || fail "Test inventory does not match this immutable shard plan."
  [ "$(digest "$(jq -cS 'del(.plan_digest)' "${plan}")")" = "${plan_digest}" ] || fail "Test shard plan digest does not match its immutable contents."
  mkdir -p "${output}"

  local shard id manifest manifest_attempt payload status aggregate_status="pass" expected_total payload_stream evidence_stream selected_attempt failed_count stem
  payload_stream="$(mktemp)"
  evidence_stream="$(mktemp)"
  trap 'rm -f -- "${payload_stream:-}" "${evidence_stream:-}"' EXIT
  stem="$(command_output_stem "${command}")"
  while IFS= read -r shard; do
    id="$(jq -r .id <<< "${shard}")"; matches=(); selected_attempt=0
    while IFS= read -r manifest; do
      if jq -e --arg phase "${phase}" --arg command "${command}" --arg id "${id}" --arg inventory_digest "${inventory_digest}" --arg plan_digest "${plan_digest}" --argjson attempt "${attempt}" '
        .phase == $phase and .command == $command and .shard_id == $id and .inventory_digest == $inventory_digest and .plan_digest == $plan_digest
        and (.run_attempt | type == "number" and floor == . and . > 0 and . <= $attempt)
        and (.results[$command] == "pass" or .results[$command] == "fail" or .results[$command] == "timeout")
      ' "${manifest}" >/dev/null 2>&1; then
        manifest_attempt="$(jq -r .run_attempt "${manifest}")"
        if [ "${manifest_attempt}" -gt "${selected_attempt}" ]; then
          matches=("${manifest}")
          selected_attempt="${manifest_attempt}"
        elif [ "${manifest_attempt}" -eq "${selected_attempt}" ]; then
          matches+=("${manifest}")
        fi
      fi
    done < <(find "${root}" -path "*/${phase}/manifest.json" -type f -print | sort)
    [ "${#matches[@]}" -eq 1 ] || fail "Expected exactly one newest valid ${phase} shard evidence manifest for ${id} at or before attempt ${attempt}."
    manifest="${matches[0]}"; status="$(jq -r --arg command "${command}" '.results[$command]' "${manifest}")"; payload="$(dirname "${manifest}")/homeboy-ci-results/$(command_result_filename "${command}")"
    case "${status}" in
      timeout) aggregate_status="timeout" ;;
      fail) [ "${aggregate_status}" = timeout ] || aggregate_status="fail" ;;
    esac
    if [ ! -f "${payload}" ]; then
      [ "${status}" = timeout ] || fail "${phase} shard ${id} is ${status} but has no structured $(command_result_filename "${command}") result."
      continue
    fi
    expected_total="$(jq '.tests | length' <<< "${shard}")"
    jq -ce --arg root "${command%% *}" --arg phase_status "${status}" '
      if .schema == "homeboy/command-result/v3" and .command == $root and (.success | type == "boolean") and (.status | type == "string") and (.exit_code | type == "number" and floor == .)
        and (if $phase_status == "timeout"
          then .success == false and .status == "failed" and .exit_code == 124
          else (if .success then .status == "succeeded" and .exit_code == 0 else .status == "failed" and .exit_code != 0 end)
            and (.data.test_counts | type == "object") and all(.data.test_counts.passed, .data.test_counts.failed, .data.test_counts.skipped, .data.test_counts.total; type == "number" and . >= 0 and floor == .)
            and (.data.test_counts.passed + .data.test_counts.failed + .data.test_counts.skipped == .data.test_counts.total)
            and (if $phase_status == "pass" then .success == true and .exit_code == 0 and .data.test_counts.failed == 0
                  else .success == false and .exit_code != 0 end)
          end)
      then if $phase_status == "timeout"
        then {data:{test_counts:{passed:0,failed:0,skipped:0,total:0}}}
        else {data:{test_counts:{passed:.data.test_counts.passed,failed:.data.test_counts.failed,skipped:.data.test_counts.skipped,total:.data.test_counts.total}}}
        end
      else empty
      end
    ' "${payload}" >> "${payload_stream}" || fail "${phase} shard ${id} has invalid structured $(command_result_filename "${command}") counts."
    if [ "${status}" = pass ]; then
      [ "$(jq '.data.test_counts.total' "${payload}")" = "${expected_total}" ] || fail "${phase} shard ${id} structured total does not match its planned membership."
    fi
    failed_count="$(jq -r '.data.test_counts.failed // 0' "${payload}")"
    jq -cn \
      --arg id "${id}" \
      --arg status "${status}" \
      --arg inventory "$(dirname "${manifest}")/homeboy-ci-results/${stem}.test-inventory.json" \
      --arg outcomes "$(dirname "${manifest}")/homeboy-ci-results/${stem}.test-outcomes.json" \
      --argjson failed_count "${failed_count}" \
      '{id:$id,status:$status,failed_count:$failed_count,inventory:$inventory,outcomes:$outcomes}' >> "${evidence_stream}"
    cp "${payload}" "${output}/${id}-$(command_result_filename "${command}")"
  done < <(jq -c '.shards[]' "${plan}")
  local inventory_total
  inventory_total="$(jq '.tests | length' "${inventory}")"
  if ! jq -n --slurpfile payloads /dev/stdin --arg root "${command%% *}" --arg plan_digest "${plan_digest}" --arg status "${aggregate_status}" --argjson inventory_total "${inventory_total}" '
    {schema:"homeboy/command-result/v3",command:$root,success:($status == "pass"),status:(if $status == "pass" then "succeeded" else "failed" end),exit_code:(if $status == "pass" then 0 elif $status == "timeout" then 124 else 1 end),
     data:{test_counts:{passed:([$payloads[].data.test_counts.passed] | add // 0),failed:([$payloads[].data.test_counts.failed] | add // 0),skipped:([$payloads[].data.test_counts.skipped] | add // 0),total:([$payloads[].data.test_counts.total] | add // 0)},shard_plan_digest:$plan_digest,shard_count:($payloads|length)}}
    | if $status != "pass" or .data.test_counts.total == $inventory_total then . else error("incomplete shard totals") end
  ' < "${payload_stream}" > "${output}/$(command_result_filename "${command}")"; then
    fail "${phase} shard totals do not cover the complete inventory."
  fi
  python3 "${SCRIPT_DIR}/aggregate-test-evidence.py" "${plan}" "${inventory}" "${command}" "${output}" < "${evidence_stream}" \
    || fail "${phase} shard Test outcome/inventory pairs are incomplete or invalid."
  printf '{"%s":"%s"}\n' "${command}" "${aggregate_status}" > "${output}/results.json"
)

# Validate one uploaded shard before its producing job publishes a verdict. This
# intentionally accepts valid failed and timed-out evidence, then returns
# non-zero so only a validated pass can leave the candidate shard green.
validate_terminal() (
  local root="${TEST_SHARD_TERMINAL_ROOT:?TEST_SHARD_TERMINAL_ROOT is required}"
  local plan="${TEST_SHARD_PLAN_FILE:?TEST_SHARD_PLAN_FILE is required}"
  local phase="${TEST_SHARD_PHASE:?TEST_SHARD_PHASE is required}"
  local command="${TEST_SHARD_COMMAND:?TEST_SHARD_COMMAND is required}"
  local id="${TEST_SHARD_ID:?TEST_SHARD_ID is required}"
  local attempt="${RUN_ATTEMPT:?RUN_ATTEMPT is required}"
  local manifest="${root}/manifest.json"
  local inventory_digest plan_digest status payload expected_total

  [ -f "${manifest}" ] || fail "${phase} shard ${id} terminal provenance manifest is missing."
  inventory_digest="$(jq -r .inventory_digest "${plan}")"
  plan_digest="$(jq -r .plan_digest "${plan}")"
  jq -e --arg phase "${phase}" --arg command "${command}" --arg id "${id}" --arg inventory_digest "${inventory_digest}" --arg plan_digest "${plan_digest}" --argjson attempt "${attempt}" '
    .phase == $phase and .command == $command and .shard_id == $id and .inventory_digest == $inventory_digest and .plan_digest == $plan_digest
    and .run_attempt == $attempt
    and (.results[$command] == "pass" or .results[$command] == "fail" or .results[$command] == "timeout")
  ' "${manifest}" >/dev/null || fail "${phase} shard ${id} terminal provenance manifest is malformed or contradicts its immutable plan."

  status="$(jq -r --arg command "${command}" '.results[$command]' "${manifest}")"
  payload="${root}/homeboy-ci-results/$(command_result_filename "${command}")"
  [ -f "${payload}" ] || fail "${phase} shard ${id} terminal structured result is missing."
  expected_total="$(jq -r --arg id "${id}" '.shards[] | select(.id == $id) | .tests | length' "${plan}")"
  jq -e --arg root "${command%% *}" --arg status "${status}" --argjson expected_total "${expected_total}" '
    .schema == "homeboy/command-result/v3" and .command == $root
    and (.success | type == "boolean") and (.status | type == "string")
    and (.exit_code | type == "number" and floor == .)
    and (if $status == "timeout" then
      .success == false and .status == "failed" and .exit_code == 124
    else
      (if .success then .status == "succeeded" and .exit_code == 0 else .status == "failed" and .exit_code != 0 end)
      and (.data.test_counts | type == "object")
      and all(.data.test_counts.passed, .data.test_counts.failed, .data.test_counts.skipped, .data.test_counts.total; type == "number" and . >= 0 and floor == .)
      and (.data.test_counts.passed + .data.test_counts.failed + .data.test_counts.skipped == .data.test_counts.total)
      and (if $status == "pass" then .success == true and .data.test_counts.failed == 0 and .data.test_counts.total == $expected_total else .success == false end)
    end)
  ' "${payload}" >/dev/null || fail "${phase} shard ${id} terminal structured result is malformed or contradicts its manifest."
  [ "${status}" = pass ] || fail "${phase} shard ${id} reported terminal ${status} evidence."
)

attach_budget() {
  local plan="${TEST_SHARD_PLAN_FILE:?TEST_SHARD_PLAN_FILE is required}"
  local budget="${TEST_SCOPE_BUDGET_FILE:?TEST_SCOPE_BUDGET_FILE is required}"
  [ -f "${plan}" ] && [ -f "${budget}" ] || fail "Test shard plan and budget evidence are required."
  jq -e '.schema == "homeboy/test-scope-budget/v1" and (.verdict == "fits" or .verdict == "unknown" or .verdict == "overridden") and (.max_estimate_ms == null or (.max_estimate_ms | type == "number" and . >= 0)) and (.budget_seconds | type == "number" and . > 0) and (.override | type == "boolean")' "${budget}" >/dev/null || fail "Test budget evidence is invalid."
  local digest_value
  jq --slurpfile budget "${budget}" 'del(.plan_digest) + {budget:$budget[0]}' "${plan}" > "${plan}.tmp"
  digest_value="$(digest "$(jq -cS . "${plan}.tmp")")"
  jq --arg digest "${digest_value}" '. + {plan_digest:$digest}' "${plan}.tmp" > "${plan}"
  rm -f "${plan}.tmp"
}

case "${1:-}" in plan) plan ;; aggregate) aggregate ;; validate-terminal) validate_terminal ;; attach-budget) attach_budget ;; *) echo "usage: $0 plan|aggregate|validate-terminal|attach-budget" >&2; exit 2 ;; esac
