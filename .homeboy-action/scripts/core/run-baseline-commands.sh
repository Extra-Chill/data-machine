#!/usr/bin/env bash

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"

EFFECTIVE_COMMANDS="${COMMANDS:-review audit,review lint,review test}"
BASELINE_COMMANDS_INPUT="${BASELINE_COMMANDS:-auto}"

if [ "${HOMEBOY_DIFFERENTIAL_GATING:-false}" != "true" ]; then
  echo "Differential gating disabled; skipping baseline run"
  exit 0
fi

if [ "${SCOPE_CONTEXT:-}" != "pr" ] || [ -z "${SCOPE_BASE_REF:-}" ]; then
  echo "Differential gating requires PR scope with a resolved base ref; skipping baseline run"
  exit 0
fi

TRACKED_DIRTY="$(git status --porcelain --untracked-files=no)"
if [ -n "${TRACKED_DIRTY}" ]; then
  echo "::warning::Tracked changes exist before the baseline checkout; skipping differential baseline run"
  exit 0
fi

# A baseline exists to excuse the candidate for breakage that predates it. When
# the candidate PASSED there is nothing to excuse: no baseline result can change
# a passing verdict, so rerunning the whole command at the base ref buys nothing.
#
# It was being rerun unconditionally. Measured on Extra-Chill/homeboy, the Audit
# baseline is 684s and the Lint baseline 110s -- Lint's real work is 137s, so its
# baseline was +80% for no verdict (homeboy#11751 W1-10).
#
# `select-test-baseline.sh` already makes exactly this decision for the sharded
# Test path; this brings the unsharded audit/lint path in line with it.
#
# Fail-safe: an absent or unparseable CANDIDATE_RESULTS keeps every baseline, so
# a caller that does not supply candidate status is never silently degraded into
# skipping the comparison.
candidate_status() {
  local cmd="$1"
  [ -n "${CANDIDATE_RESULTS:-}" ] || { printf 'unknown'; return; }
  printf '%s' "${CANDIDATE_RESULTS}" | jq -r --arg cmd "${cmd}" '.[$cmd] // "unknown"' 2>/dev/null || printf 'unknown'
}

BASELINE_RUN_COMMANDS=()
SKIPPED_PASSING_COMMANDS=()
ORDERED_COMMANDS="$(resolve_baseline_commands "${EFFECTIVE_COMMANDS}" "${BASELINE_COMMANDS_INPUT}")"
IFS=',' read -ra CMD_ARRAY <<< "${ORDERED_COMMANDS}"
for CMD in "${CMD_ARRAY[@]}"; do
  CMD="$(echo "${CMD}" | xargs)"
  case "$(quality_base_command "${CMD}")" in
    audit|lint|test)
      if [ "$(candidate_status "${CMD}")" = pass ]; then
        SKIPPED_PASSING_COMMANDS+=("${CMD}")
        continue
      fi
      BASELINE_RUN_COMMANDS+=("${CMD}")
      ;;
  esac
done

if [ "${#SKIPPED_PASSING_COMMANDS[@]}" -gt 0 ]; then
  echo "Candidate passed ${SKIPPED_PASSING_COMMANDS[*]}; skipping their baseline runs (a baseline cannot change a passing verdict)"
fi

if [ "${#BASELINE_RUN_COMMANDS[@]}" -eq 0 ]; then
  if [ "${#SKIPPED_PASSING_COMMANDS[@]}" -gt 0 ]; then
    echo "Every requested command passed on the candidate; no baseline comparison is needed"
  else
    echo "No audit/lint/test commands requested; skipping differential baseline run"
  fi
  exit 0
fi

ORIGINAL_REF="$(git symbolic-ref --quiet --short HEAD 2>/dev/null || git rev-parse HEAD)"
BASE_OUTPUT_DIR="$(mktemp -d)"
echo "HOMEBOY_BASE_OUTPUT_DIR=${BASE_OUTPUT_DIR}" >> "${GITHUB_ENV}"
BASELINE_STATUS_JSON="${BASE_OUTPUT_DIR}/baseline-status.json"
printf '{}\n' > "${BASELINE_STATUS_JSON}"

restore_original_ref() {
  git checkout -q "${ORIGINAL_REF}" || true
}
trap restore_original_ref EXIT

echo "Checking out baseline ref ${SCOPE_BASE_REF} for differential gating"
git checkout -q --detach "${SCOPE_BASE_REF}"

COMP_ID="$(resolve_component_id)"
WORKSPACE="$(resolve_workspace)"
GROUP_PREFIX="${RUN_GROUP_PREFIX:-homeboy baseline}"

for CMD in "${BASELINE_RUN_COMMANDS[@]}"; do
  OUTPUT_STEM="$(command_output_stem "${CMD}")"
  OUTPUT_JSON="${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.json"
  rm -f "${OUTPUT_JSON}"
  if [ "$(quality_base_command "${CMD}")" = "test" ]; then
    rm -f \
      "${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.test-inventory.json" \
      "${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.test-outcomes.json"
  fi
  FULL_CMD="$(build_run_command "${CMD}" "${COMP_ID}" "${WORKSPACE}" "${OUTPUT_JSON}")"

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  Running baseline: ${FULL_CMD}"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo ""

  echo "::group::${GROUP_PREFIX} ${CMD}"
  set +e
  bash "${GITHUB_ACTION_PATH}/scripts/core/phase-progress.sh" run command_execution -- \
    bash "${GITHUB_ACTION_PATH}/scripts/core/run-with-liveness-timeout.sh" \
      --log-file "${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.log" \
      "baseline homeboy ${CMD}" bash -c "${FULL_CMD}"
  CMD_EXIT=$?
  cat "${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.log"
  set -e
  echo "::endgroup::"

  STRUCTURED_OUTPUT=false
  if [ -s "${OUTPUT_JSON}" ] && valid_command_result_output "${OUTPUT_JSON}" "${CMD}" "${CMD_EXIT}"; then
    STRUCTURED_OUTPUT=true
  else
    echo "::error::baseline homeboy ${CMD} did not write valid structured output to ${OUTPUT_JSON}"
  fi

  STATUS="pass"
  if [ "${CMD_EXIT}" -eq 0 ] && [ "${STRUCTURED_OUTPUT}" = false ]; then
    STATUS="fail"
  elif [ "${CMD_EXIT}" -eq 124 ]; then
    STATUS="timeout"
  elif [ "${CMD_EXIT}" -ne 0 ]; then
    STATUS="fail"
  fi

  tmp_status="$(mktemp)"
  jq -c \
    --arg cmd "${CMD}" \
    --arg status "${STATUS}" \
    --arg command "${FULL_CMD}" \
    --arg output_json "${OUTPUT_JSON}" \
    --arg log_path "${BASE_OUTPUT_DIR}/${OUTPUT_STEM}.log" \
    --argjson exit_code "${CMD_EXIT}" \
    --argjson structured_output "${STRUCTURED_OUTPUT}" \
    '. + {($cmd): {status: $status, exit_code: $exit_code, command: $command, output_json: $output_json, log_path: $log_path, structured_output: $structured_output}}' \
    "${BASELINE_STATUS_JSON}" > "${tmp_status}"
  mv "${tmp_status}" "${BASELINE_STATUS_JSON}"

  echo "Baseline homeboy ${CMD} exited ${CMD_EXIT}"
done

echo "Baseline outputs captured in ${BASE_OUTPUT_DIR}"
