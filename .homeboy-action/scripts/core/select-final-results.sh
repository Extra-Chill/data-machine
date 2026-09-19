#!/usr/bin/env bash

set -euo pipefail

RESULTS="${FIRST_RESULTS:-}"
COMMANDS="${COMMANDS:-}"
RESULTS_DIR="${HOMEBOY_CI_RESULTS_DIR:-${HOMEBOY_OUTPUT_DIR:-}}"
SETUP_RESULT_FILE="${HOMEBOY_SETUP_RESULT_FILE:-${RESULTS_DIR:+${RESULTS_DIR}/setup.json}}"

setup_failed() {
  [ -n "${SETUP_RESULT_FILE}" ] && [ -f "${SETUP_RESULT_FILE}" ] || return 1
  jq -e '
    .schema == "homeboy/action-setup-result/v1"
    and .phase == "dependency_build_setup"
    and (.status == "failed" or .status == "timeout")
    and (.owner | type == "string" and length > 0)
    and (.step | type == "string" and length > 0)
    and (.exit_code | type == "number")
    and (.replay_command | type == "string" and length > 0)
  ' "${SETUP_RESULT_FILE}" >/dev/null 2>&1
}

results_are_complete() {
  [ -n "${RESULTS}" ] || return 1
  printf '%s\n' "${RESULTS}" | jq -e --arg commands "${COMMANDS}" '
    . as $results |
    ($commands | split(",") | map(gsub("^\\s+|\\s+$"; "")) | map(select(length > 0))) as $expected |
    ($results | type) == "object"
    and all($expected[]; . as $command | $results[$command] == "pass" or $results[$command] == "fail" or $results[$command] == "timeout")
  ' >/dev/null 2>&1
}

# A cancelled run never reached a verdict. `setup_failed` covers a build that
# failed or timed out, but a run superseded by a newer push writes no setup
# result at all, so it would fall through to the fabricate-all-fail branch
# below and surface as a red gate that names no rule and produces no findings --
# indistinguishable from a real failure.
if [ "${HOMEBOY_RUN_CANCELLED:-false}" = "true" ] && ! results_are_complete && ! setup_failed; then
  echo "::notice::Run was cancelled before commands completed; not reporting cancellation as command failure"
  RESULTS="$(printf '%s\n' "${COMMANDS}" | jq -Rc '
    split(",") | map(gsub("^\\s+|\\s+$"; "") | select(length > 0))
    | map({key: ., value: "not_run"})
    | from_entries
  ')"
elif ! results_are_complete; then
  if setup_failed; then
    RESULTS="$(printf '%s\n' "${COMMANDS}" | jq -Rc '
      split(",") | map(gsub("^\\s+|\\s+$"; "") | select(length > 0))
      | map({key: ., value: "not_run"})
      | from_entries + {setup: "fail"}
    ')"
    setup_owner="$(jq -r '.owner' "${SETUP_RESULT_FILE}")"
    setup_step="$(jq -r '.step' "${SETUP_RESULT_FILE}")"
    echo "::error::${setup_owner} failed during ${setup_step}; requested quality commands were not run."
  elif [ -n "${COMMANDS}" ]; then
    RESULTS="$(printf '%s\n' "${COMMANDS}" | jq -Rc '
      split(",") | map(gsub("^\\s+|\\s+$"; "") | select(length > 0)) | map({key: ., value: "fail"}) | from_entries
    ')"
    echo "::error::Current Homeboy command results were missing, malformed, interrupted, or incomplete; marking all expected commands failed."
  else
    RESULTS='{}'
  fi
fi

if [ "${HOMEBOY_DIFFERENTIAL_GATING:-false}" = "true" ] \
  && [ -n "${RESULTS_DIR}" ] \
  && [ -d "${RESULTS_DIR}" ] \
  && [ -n "${HOMEBOY_BASE_OUTPUT_DIR:-}" ] \
  && [ -d "${HOMEBOY_BASE_OUTPUT_DIR:-}" ]; then
  for baseline_file in "${HOMEBOY_BASE_OUTPUT_DIR}"/*.json; do
    [ -f "${baseline_file}" ] || continue
    name="$(basename "${baseline_file}")"
    if [ "${name}" = "baseline-status.json" ]; then
      cp "${baseline_file}" "${RESULTS_DIR}/${name}"
    else
      cp "${baseline_file}" "${RESULTS_DIR}/baseline-${name}"
    fi
  done
  for baseline_log in "${HOMEBOY_BASE_OUTPUT_DIR}"/*.log; do
    [ -f "${baseline_log}" ] || continue
    cp "${baseline_log}" "${RESULTS_DIR}/baseline-$(basename "${baseline_log}")"
  done

  RESULTS="$(python3 "${GITHUB_ACTION_PATH}/scripts/core/apply-differential-gate.py" \
    "${RESULTS}" \
    "${RESULTS_DIR}" \
    "${HOMEBOY_BASE_OUTPUT_DIR}")"
fi

{
  echo 'results<<__HOMEBOY_RESULTS__'
  printf '%s\n' "${RESULTS}"
  echo '__HOMEBOY_RESULTS__'
} >> "${GITHUB_OUTPUT}"
