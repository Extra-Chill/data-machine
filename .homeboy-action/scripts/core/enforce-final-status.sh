#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/lib.sh"

OPERATIONS_RESULTS="${OPERATIONS_RESULTS:-}"
RESULTS_DIR="${HOMEBOY_CI_RESULTS_DIR:-${HOMEBOY_OUTPUT_DIR:-}}"
SETUP_RESULT_FILE="${HOMEBOY_SETUP_RESULT_FILE:-${RESULTS_DIR:+${RESULTS_DIR}/setup.json}}"
HAS_QUALITY_COMMANDS=true
HAS_OPERATIONS_COMMANDS=true

validate_results_json() {
  local label="$1"
  local json="$2"

  if [ -z "${json}" ]; then
    return 0
  fi

  if ! printf '%s\n' "${json}" | jq -e 'type == "object"' > /dev/null 2>&1; then
    echo "::error::${label} results are not valid JSON object data"
    return 1
  fi
}

# Same reasoning as the stale-PR case below: a cancelled run never reached a
# verdict, so there is nothing to enforce. Exiting non-zero here reports the
# cancellation itself as a gate failure.
if [ "${HOMEBOY_RUN_CANCELLED:-false}" = "true" ]; then
  echo "Run was cancelled before commands completed — nothing to enforce"
  exit 0
fi

if [ "${PR_ACTIVE:-}" = "false" ]; then
  echo "PR was merged or closed before final enforcement — ignoring stale command results"
  exit 0
fi

validate_results_json "Quality command" "${RESULTS:-}"
validate_results_json "Operations command" "${OPERATIONS_RESULTS}"

emit_terminal_diagnostics() {
  local command diagnostic

  while IFS= read -r command; do
    [ -n "${command}" ] || continue
    while IFS= read -r diagnostic; do
      [ -n "${diagnostic}" ] || continue
      printf '::error::homeboy %s terminal diagnostic: %s\n' "${command}" "${diagnostic}"
    done < <(observation_terminal_diagnostics "${HOMEBOY_OBSERVATIONS_DIR:-}" "${command}")
  done < <(jq -r 'to_entries[] | select(.key != "setup" and .value == "fail") | .key' <<< "${RESULTS:-{}}" 2>/dev/null || true)
}

if [ -z "${RESULTS:-}" ] || [ "${RESULTS}" = "{}" ]; then
  HAS_QUALITY_COMMANDS=false

  # If there are no quality commands, empty quality results are expected.
  COMMANDS="${COMMANDS:-}"
  if [ -z "${COMMANDS}" ] && [ -z "${OPERATIONS_RESULTS}" ]; then
    echo "No quality gate commands to enforce"
    exit 0
  fi

  # If we have operations results but no quality results, that's fine
  if [ -z "${COMMANDS}" ] && [ -n "${OPERATIONS_RESULTS}" ]; then
    HAS_QUALITY_COMMANDS=false
  elif [ -z "${OPERATIONS_RESULTS}" ]; then
    echo "::error::No command results were produced"
    exit 1
  fi
fi

FAILED=false

if [ -n "${SETUP_RESULT_FILE}" ] && [ -f "${SETUP_RESULT_FILE}" ] \
  && jq -e '.schema == "homeboy/action-setup-result/v1" and (.status == "failed" or .status == "timeout")' "${SETUP_RESULT_FILE}" >/dev/null 2>&1; then
  jq -r '"::error title=Homeboy setup failed [" + .owner + "]::" + .step + " " + .status + " (exit " + (.exit_code | tostring) + "). " + (if .diagnostic == "" then "Inspect the retained setup log." else .diagnostic end) + " Reproduce: " + .replay_command' "${SETUP_RESULT_FILE}"
  FAILED=true
fi

# Check quality command results
if [ "${HAS_QUALITY_COMMANDS}" = true ]; then
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.key != "setup" and (.value == "fail" or .value == "invalid_evidence" or .value == "no_comparable_evidence"))' > /dev/null; then
    emit_terminal_diagnostics
    echo "::error::One or more quality commands failed"
    FAILED=true
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "timeout")' > /dev/null; then
    echo "::error::One or more quality commands timed out; inspect the retained command logs above."
    FAILED=true
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "baseline_red")' > /dev/null; then
    echo "::warning::One or more quality commands were inconclusive because the baseline is already red"
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "inconclusive")' > /dev/null; then
    echo "::warning::One or more quality commands were inconclusive; preserving evidence without failing the PR"
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "no_measurement" and (.key | test("^(review[[:space:]]+)?(lint|test)([[:space:]]|$)")))' > /dev/null; then
    echo "::error::Required lint or test commands produced no measurement; this is not acceptable gate evidence."
    FAILED=true
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "no_measurement")' > /dev/null; then
    echo "::warning::One or more optional quality commands produced no measurement on either the candidate or the baseline."
  fi
  if printf '%s\n' "${RESULTS}" | jq -e 'to_entries | any(.value == "not_run")' > /dev/null; then
    echo "::warning::One or more requested quality commands were not run because setup did not complete."
  fi
fi

# Check operations command results
if [ -n "${OPERATIONS_RESULTS}" ] && [ "${OPERATIONS_RESULTS}" != "{}" ]; then
  if printf '%s\n' "${OPERATIONS_RESULTS}" | jq -e 'to_entries | any(.value == "fail")' > /dev/null; then
    echo "::error::One or more operations commands (fleet/deploy) failed"
    FAILED=true
  fi
  if printf '%s\n' "${OPERATIONS_RESULTS}" | jq -e 'to_entries | any(.value == "timeout")' > /dev/null; then
    echo "::error::One or more operations commands timed out; inspect the retained command logs above."
    FAILED=true
  fi
fi

if [ "${FAILED}" = true ]; then
  exit 1
fi

echo "All Homeboy commands passed"
