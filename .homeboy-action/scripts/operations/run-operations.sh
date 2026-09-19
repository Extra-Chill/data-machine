#!/usr/bin/env bash
#
# Run fleet and deploy commands.
#
# Unlike quality-gate commands (audit/lint/test), fleet and deploy are
# "operations" commands that talk to remote servers via SSH. They use
# their own argument structure and don't take component/workspace/scope
# flags from the action — the full command is passed through as-is.
#
# Commands are specified as the full homeboy invocation after the base
# command, e.g.:
#   fleet exec my-fleet -- homeboy upgrade
#   deploy my-project --all
#   deploy data-machine --fleet production
#   fleet check my-fleet
#   fleet status my-fleet
#
# Env vars:
#   OPERATIONS_COMMANDS — newline or comma-separated list of commands
#   EXTRA_ARGS          — additional args appended to each command
#   RUN_GROUP_PREFIX    — log group prefix (default: homeboy)
#
# Outputs (GITHUB_OUTPUT):
#   results — JSON object { "fleet exec ...": "pass"|"fail"|"timeout", ... }
#   any-failed — true|false

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"

OPERATIONS_COMMANDS="${OPERATIONS_COMMANDS:-}"
EXTRA_ARGS="${EXTRA_ARGS:-}"
GROUP_PREFIX="${RUN_GROUP_PREFIX:-homeboy}"

if [ -z "${OPERATIONS_COMMANDS}" ]; then
  echo "No operations commands to run"
  echo "results={}" >> "${GITHUB_OUTPUT}"
  echo "any-failed=false" >> "${GITHUB_OUTPUT}"
  exit 0
fi

HOMEBOY_OUTPUT_DIR="${HOMEBOY_OUTPUT_DIR:-$(mktemp -d)}"
RESULTS='{}'
OVERALL_EXIT=0
CMD_INDEX=0

# Parse commands: support both comma-separated and newline-separated
# Normalize newlines to commas, then split
NORMALIZED_COMMANDS="$(printf '%s' "${OPERATIONS_COMMANDS}" | tr '\n' ',' | sed 's/,,*/,/g; s/^,//; s/,$//')"
IFS=',' read -ra CMD_ARRAY <<< "${NORMALIZED_COMMANDS}"

for CMD in "${CMD_ARRAY[@]}"; do
  CMD="$(echo "${CMD}" | xargs)"
  [ -z "${CMD}" ] && continue

  CMD_INDEX=$((CMD_INDEX + 1))

  # Build the full command
  # Fleet and deploy commands are passthrough — we prepend "homeboy" and pass as-is
  FULL_CMD="homeboy ${CMD}"

  # Add output file for structured results
  OUTPUT_STEM="operations-${CMD_INDEX}"
  OUTPUT_JSON="${HOMEBOY_OUTPUT_DIR}/${OUTPUT_STEM}.json"
  FULL_CMD="homeboy --output ${OUTPUT_JSON} ${CMD}"

  # Append extra args if provided
  if [ -n "${EXTRA_ARGS}" ]; then
    FULL_CMD="${FULL_CMD} ${EXTRA_ARGS}"
  fi

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  Running: ${FULL_CMD}"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo ""

  echo "::group::${GROUP_PREFIX} ${CMD}"
  CMD_EXIT=0
  set +e
  bash "${GITHUB_ACTION_PATH}/scripts/core/run-with-liveness-timeout.sh" \
    --log-file "${HOMEBOY_OUTPUT_DIR}/${OUTPUT_STEM}.log" \
    "operations homeboy ${CMD}" bash -c "${FULL_CMD}"
  CMD_EXIT=$?
  cat "${HOMEBOY_OUTPUT_DIR}/${OUTPUT_STEM}.log"
  set -e
  echo "::endgroup::"

  # Use a short label for the results key
  RESULT_KEY="${CMD}"

  STRUCTURED_OUTPUT=true
  if [ ! -s "${OUTPUT_JSON}" ] || ! valid_command_result_output "${OUTPUT_JSON}" "${CMD}" "${CMD_EXIT}"; then
    STRUCTURED_OUTPUT=false
    echo "::error::homeboy ${CMD} did not write valid structured output to ${OUTPUT_JSON}"
  fi

  if [ "${CMD_EXIT}" -eq 0 ] && [ "${STRUCTURED_OUTPUT}" = true ]; then
    echo "::notice::homeboy ${CMD}: PASSED"
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${RESULT_KEY}" '. + {($cmd): "pass"}')
  elif [ "${CMD_EXIT}" -eq 0 ]; then
    echo "::error::homeboy ${CMD}: FAILED because required structured output was missing or malformed."
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${RESULT_KEY}" '. + {($cmd): "fail"}')
    OVERALL_EXIT=1
  elif [ "${CMD_EXIT}" -eq 124 ]; then
    echo "::error::homeboy ${CMD}: TIMED OUT (exit code 124); inspect the retained command log above."
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${RESULT_KEY}" '. + {($cmd): "timeout"}')
    OVERALL_EXIT=1
  else
    echo "::error::homeboy ${CMD}: FAILED (exit code ${CMD_EXIT})"
    RESULTS=$(echo "${RESULTS}" | jq -c --arg cmd "${RESULT_KEY}" '. + {($cmd): "fail"}')
    OVERALL_EXIT=1
  fi
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Operations results: ${RESULTS}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo "results=${RESULTS}" >> "${GITHUB_OUTPUT}"

if [ "${OVERALL_EXIT}" -ne 0 ]; then
  echo "any-failed=true" >> "${GITHUB_OUTPUT}"
else
  echo "any-failed=false" >> "${GITHUB_OUTPUT}"
fi

exit "${OVERALL_EXIT}"
