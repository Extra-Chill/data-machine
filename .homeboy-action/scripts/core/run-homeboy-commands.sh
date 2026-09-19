#!/usr/bin/env bash

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"

# v2: prefer RESOLVED_COMMANDS (quality-only from resolve-commands.sh), fall back to review-nested defaults.
EFFECTIVE_COMMANDS="${RESOLVED_COMMANDS:-${COMMANDS:-review audit,review lint,review test}}"

# If no quality commands to run (e.g. operations-only mode), exit cleanly
if [ -z "${EFFECTIVE_COMMANDS}" ]; then
  echo "No quality commands to run"
  echo "results={}" >> "${GITHUB_OUTPUT}"
  exit 0
fi

COMP_ID="$(resolve_component_id)"
WORKSPACE="$(resolve_workspace)"
RESULTS='{}'
OVERALL_EXIT=0
GROUP_PREFIX="${RUN_GROUP_PREFIX:-homeboy}"

HOMEBOY_OUTPUT_DIR=$(mktemp -d)
echo "HOMEBOY_OUTPUT_DIR=${HOMEBOY_OUTPUT_DIR}" >> "${GITHUB_ENV}"

HOMEBOY_CI_RESULTS_DIR="${GITHUB_WORKSPACE:-$(pwd)}/homeboy-ci-results"
if [ -L "${HOMEBOY_CI_RESULTS_DIR}" ] || { [ -e "${HOMEBOY_CI_RESULTS_DIR}" ] && [ ! -d "${HOMEBOY_CI_RESULTS_DIR}" ]; }; then
  echo "::error::HOMEBOY_CI_RESULTS_DIR must be a real directory: ${HOMEBOY_CI_RESULTS_DIR}"
  exit 1
fi
mkdir -p "${HOMEBOY_CI_RESULTS_DIR}"
echo "HOMEBOY_CI_RESULTS_DIR=${HOMEBOY_CI_RESULTS_DIR}" >> "${GITHUB_ENV}"

HOMEBOY_ANNOTATIONS_DIR=$(mktemp -d)
echo "HOMEBOY_ANNOTATIONS_DIR=${HOMEBOY_ANNOTATIONS_DIR}" >> "${GITHUB_ENV}"
export HOMEBOY_ANNOTATIONS_DIR

# Enforce canonical order: review audit → review lint → review test
ORDERED_COMMANDS="$(canonicalize_commands "${EFFECTIVE_COMMANDS}")"
IFS=',' read -ra CMD_ARRAY <<< "${ORDERED_COMMANDS}"
HAS_LINT_COMMAND="$(has_lint_command "${EFFECTIVE_COMMANDS}")"

for CMD in "${CMD_ARRAY[@]}"; do
  CMD=$(echo "${CMD}" | xargs)

  if [ "$(quality_base_command "${CMD}")" = "test" ] && [ "${HAS_LINT_COMMAND}" = "true" ]; then
    export HOMEBOY_SKIP_LINT=1
  else
    unset HOMEBOY_SKIP_LINT 2>/dev/null || true
  fi

  OUTPUT_STEM="$(command_output_stem "${CMD}")"
  if [ "$(printf '%s' "${CMD}" | awk '{print $1}')" = "bench" ]; then
    CI_RESULT_JSON="${HOMEBOY_CI_RESULTS_DIR}/bench.json"
  else
    CI_RESULT_JSON="${HOMEBOY_CI_RESULTS_DIR}/$(command_result_filename "${CMD}")"
  fi
  # This is the one durable result path consumed by artifacts and reporting.
  OUTPUT_JSON="${CI_RESULT_JSON}"

  if [ -L "${HOMEBOY_CI_RESULTS_DIR}" ] || [ ! -d "${HOMEBOY_CI_RESULTS_DIR}" ]; then
    echo "::error::HOMEBOY_CI_RESULTS_DIR must remain a real directory: ${HOMEBOY_CI_RESULTS_DIR}"
    exit 1
  fi
  if [ -e "${CI_RESULT_JSON}" ] && [ ! -f "${CI_RESULT_JSON}" ] && [ ! -L "${CI_RESULT_JSON}" ]; then
    echo "::error::homeboy ${CMD} result target must be a file: ${CI_RESULT_JSON}"
    exit 1
  fi
  # Test evidence is a single three-file unit. Remove the entire prior unit so
  # a timeout or producer failure cannot leave stale sidecars beside this run.
  rm -f "${CI_RESULT_JSON}"
  if [ "$(quality_base_command "${CMD}")" = "test" ]; then
    rm -f \
      "${HOMEBOY_CI_RESULTS_DIR}/${OUTPUT_STEM}.test-inventory.json" \
      "${HOMEBOY_CI_RESULTS_DIR}/${OUTPUT_STEM}.test-outcomes.json"
  fi

  FULL_CMD="$(build_run_command "${CMD}" "${COMP_ID}" "${WORKSPACE}" "${OUTPUT_JSON}")"

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  Running: ${FULL_CMD}"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo ""

  echo "::group::${GROUP_PREFIX} ${CMD}"
  CMD_EXIT=0
  set +e
  bash "${GITHUB_ACTION_PATH}/scripts/core/phase-progress.sh" run command_execution -- \
    bash "${GITHUB_ACTION_PATH}/scripts/core/run-with-liveness-timeout.sh" \
      --log-file "${HOMEBOY_OUTPUT_DIR}/${OUTPUT_STEM}.log" \
      "homeboy ${CMD}" bash -c "${FULL_CMD}"
  CMD_EXIT=$?
  cat "${HOMEBOY_OUTPUT_DIR}/${OUTPUT_STEM}.log"
  set -e
  echo "::endgroup::"

  STRUCTURED_OUTPUT=true
  if [ -L "${HOMEBOY_CI_RESULTS_DIR}" ] || [ ! -d "${HOMEBOY_CI_RESULTS_DIR}" ]; then
    STRUCTURED_OUTPUT=false
    echo "::error::HOMEBOY_CI_RESULTS_DIR must remain a real directory: ${HOMEBOY_CI_RESULTS_DIR}"
  elif [ -L "${CI_RESULT_JSON}" ] || { [ -e "${CI_RESULT_JSON}" ] && [ ! -f "${CI_RESULT_JSON}" ]; } || [ ! -s "${OUTPUT_JSON}" ] || ! valid_command_result_output "${OUTPUT_JSON}" "${CMD}" "${CMD_EXIT}"; then
    STRUCTURED_OUTPUT=false
    rm -f "${CI_RESULT_JSON}"
    echo "::error::homeboy ${CMD} did not write valid structured output to ${OUTPUT_JSON}"
  fi

  if [ "${CMD_EXIT}" -eq 0 ] && [ "${STRUCTURED_OUTPUT}" = true ]; then
    echo "::notice::homeboy ${CMD}: PASSED"
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${CMD}" '. + {($cmd): "pass"}')
  elif [ "${CMD_EXIT}" -eq 0 ]; then
    echo "::error::homeboy ${CMD}: FAILED because required structured output was missing or malformed."
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${CMD}" '. + {($cmd): "fail"}')
    OVERALL_EXIT=1
  elif [ "${CMD_EXIT}" -eq 124 ]; then
    echo "::error::homeboy ${CMD}: TIMED OUT (exit code 124); inspect the retained command log above."
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${CMD}" '. + {($cmd): "timeout"}')
    OVERALL_EXIT=1
  else
    echo "::error::homeboy ${CMD}: FAILED (exit code ${CMD_EXIT})"
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${CMD}" '. + {($cmd): "fail"}')
    OVERALL_EXIT=1
  fi
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Results: ${RESULTS}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo "results=${RESULTS}" >> "${GITHUB_OUTPUT}"
exit "${OVERALL_EXIT}"
