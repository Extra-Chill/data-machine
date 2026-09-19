#!/usr/bin/env bash
# Reconcile all durable quality-command outputs into rolling tracker findings.

set -euo pipefail

COMP_ID="${COMPONENT_NAME:-$(basename "${GITHUB_REPOSITORY}")}"
OUTPUT_DIR="${HOMEBOY_CI_RESULTS_DIR:-${HOMEBOY_OUTPUT_DIR:-}}"
SETUP_RESULT_FILE="${HOMEBOY_SETUP_RESULT_FILE:-${OUTPUT_DIR:+${OUTPUT_DIR}/setup.json}}"
RUN_URL="${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}"
RECONCILE_PATH="${GITHUB_WORKSPACE:-$(pwd)}"
RESULTS_JSON="${RESULTS:-}"
COMMANDS_LIST="${COMMANDS:-}"
EXPECTED_LIST="${EXPECTED_COMMANDS:-}"

if [ -n "${SETUP_RESULT_FILE}" ] && [ -f "${SETUP_RESULT_FILE}" ] \
  && jq -e '.schema == "homeboy/action-setup-result/v1" and (.status == "failed" or .status == "timeout")' "${SETUP_RESULT_FILE}" >/dev/null 2>&1; then
  jq -r '"Skipping finding reconciliation because " + .owner + " failed during " + .step + "; requested quality commands were not run."' "${SETUP_RESULT_FILE}"
  exit 0
fi

FAILED_COMMANDS=""
if [ -n "${RESULTS_JSON}" ] && [ "${RESULTS_JSON}" != "{}" ]; then
  if printf '%s\n' "${RESULTS_JSON}" | jq -e 'type == "object"' >/dev/null 2>&1; then
    FAILED_COMMANDS=$(printf '%s\n' "${RESULTS_JSON}" \
      | jq -r 'to_entries | map(select(.value == "fail")) | .[].key' 2>/dev/null \
      | paste -sd ',' -)
  else
    echo "::warning::RESULTS is not a valid JSON object — treating as empty"
  fi
fi

if [ -z "${COMMANDS_LIST}" ]; then
  echo "Nothing to categorize: no categorizable commands ran in this invocation (RESULTS is empty, COMMANDS is empty)."
  if [ -n "${EXPECTED_LIST}" ]; then
    echo "Expected commands ${EXPECTED_LIST} are tracked by sibling jobs; this invocation has no findings to file."
  fi
  exit 0
fi

result_file=$(mktemp)
trap 'rm -f "${result_file}"' EXIT

if ! homeboy runs findings reconcile-run "${COMP_ID}" \
  --output-dir "${OUTPUT_DIR}" \
  --commands "${COMMANDS_LIST}" \
  --run-url "${RUN_URL}" \
  --path "${RECONCILE_PATH}" \
  --apply \
  > "${result_file}" 2>&1; then
  echo "::warning::homeboy runs findings reconcile-run failed"
  cat "${result_file}"
  exit 1
fi

if ! jq -e '.schema == "homeboy/command-result/v3" and (.data.payload.totals | type == "object")' "${result_file}" >/dev/null 2>&1; then
  echo "::error::homeboy runs findings reconcile-run returned an invalid command result"
  cat "${result_file}"
  exit 1
fi

jq -r '
  .data.payload.commands[]
  | select(.reconcile != null)
  | "\nReconciling " + .command + " issues for " + .component_id
    + "\nSource: " + .source
    + "\nPlan:\n"
    + ([.reconcile.plan_lines[]? | "  " + .] | join("\n"))
' "${result_file}"

COMMANDS_PROCESSED=$(jq -r '.data.payload.totals.commands_processed' "${result_file}")
TOTAL_ISSUES_CREATED=$(jq -r '.data.payload.totals.issues_created' "${result_file}")
TOTAL_ISSUES_UPDATED=$(jq -r '.data.payload.totals.issues_updated' "${result_file}")
TOTAL_ISSUES_CLOSED=$(jq -r '.data.payload.totals.issues_closed' "${result_file}")
RECONCILE_FAILURES=$(jq -r '.data.payload.totals.failures' "${result_file}")

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Categorized issues summary"
echo "  Commands processed: ${COMMANDS_PROCESSED}"
echo "  Issues created: ${TOTAL_ISSUES_CREATED}"
echo "  Issues updated: ${TOTAL_ISSUES_UPDATED}"
echo "  Issues closed:  ${TOTAL_ISSUES_CLOSED}"
echo "  Failures:       ${RECONCILE_FAILURES}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ "${RECONCILE_FAILURES}" -gt 0 ]; then
  exit 1
fi

if [ "${COMMANDS_PROCESSED}" -gt 0 ]; then
  exit 0
fi

if [ -n "${FAILED_COMMANDS}" ]; then
  echo "::error::Commands failed without producing structured output for categorization: ${FAILED_COMMANDS}"
  echo "Cannot file categorized issues without structured JSON. The underlying command failure should be addressed before re-running."
  exit 1
fi

echo "Nothing to categorize: all categorizable commands either passed cleanly or produced no structured output to reconcile."
