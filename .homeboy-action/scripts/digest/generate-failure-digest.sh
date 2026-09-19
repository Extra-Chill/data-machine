#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../core/lib.sh"
source "${SCRIPT_DIR}/../pr/comment/timeout-triage.sh"

OUTPUT_DIR="${HOMEBOY_CI_RESULTS_DIR:-${HOMEBOY_OUTPUT_DIR:-}}"
RESULTS_JSON="${RESULTS:-"{}"}"
RUN_URL="${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}"
HOMEBOY_CLI_VERSION="${HOMEBOY_CLI_VERSION:-unknown}"
HOMEBOY_EXTENSION_ID="${HOMEBOY_EXTENSION_ID:-auto}"
HOMEBOY_EXTENSION_SOURCE="${HOMEBOY_EXTENSION_SOURCE:-auto}"
HOMEBOY_EXTENSION_REVISION="${HOMEBOY_EXTENSION_REVISION:-unknown}"
HOMEBOY_ACTION_REF="${HOMEBOY_ACTION_REF:-unknown}"
HOMEBOY_ACTION_REPOSITORY="${HOMEBOY_ACTION_REPOSITORY:-unknown}"
COMMANDS_CSV="${COMMANDS:-}"
COMPONENT_NAME="${COMPONENT_NAME:-${HOMEBOY_COMPONENT_ID:-}}"
SETUP_RESULT_FILE="${HOMEBOY_SETUP_RESULT_FILE:-${OUTPUT_DIR:+${OUTPUT_DIR}/setup.json}}"

has_setup_failure() {
  [ -n "${SETUP_RESULT_FILE}" ] && [ -f "${SETUP_RESULT_FILE}" ] || return 1
  jq -e '.schema == "homeboy/action-setup-result/v1" and (.status == "failed" or .status == "timeout")' "${SETUP_RESULT_FILE}" >/dev/null 2>&1
}

append_setup_failure() {
  local digest_file="$1"
  has_setup_failure || return 0

  {
    printf '\n### Setup failure\n'
    jq -r '"- Owner: **" + .owner + "**\n- Step: `" + .step + "`\n- Status: **" + .status + "** (exit `" + (.exit_code | tostring) + "`)\n- Diagnostic: " + (if .diagnostic == "" then "Inspect the retained setup log." else .diagnostic end) + "\n- Replay: `" + .replay_command + "`"' "${SETUP_RESULT_FILE}"
    printf '\n'
  } >> "${digest_file}"
}

append_local_reproduction_commands() {
  local digest_file="$1"
  local failed_commands
  local command
  local scope_args=""

  failed_commands="$(jq -r '
    to_entries[]
    | select((.value == "fail" or .value == "timeout") and (.key == "review lint" or .key == "review test"))
    | .key
  ' <<< "${RESULTS_JSON}" 2>/dev/null || true)"

  [ -n "${failed_commands}" ] || return 0

  if [ -z "${COMPONENT_NAME}" ]; then
    return 0
  fi

  if [ "${SCOPE_MODE:-full}" = "changed" ] && [ -n "${SCOPE_BASE_REF:-}" ]; then
    scope_args=" --changed-since ${SCOPE_BASE_REF}"
  fi

  {
    printf '\n### Local reproduction\n'
    printf -- '- Scope context: `%s`\n' "${SCOPE_CONTEXT:-unknown}"
    printf -- '- Scope mode: `%s`\n' "${SCOPE_MODE:-full}"
    printf -- '- Scope base ref: `%s`\n' "${SCOPE_BASE_REF:-none}"
    printf '\n```bash\n'
    while IFS= read -r command; do
      [ -n "${command}" ] || continue
      case "${command}" in
        "review "*) printf 'homeboy %s %s --path .%s\n' "${command}" "${COMPONENT_NAME}" "${scope_args}" ;;
        lint|test) printf 'homeboy review %s %s --path .%s\n' "${command}" "${COMPONENT_NAME}" "${scope_args}" ;;
      esac
    done <<< "${failed_commands}"
    printf '```\n'
  } >> "${digest_file}"
}

append_differential_evidence() {
  local digest_file="$1"
  local baseline_status_file="${OUTPUT_DIR}/baseline-status.json"
  local rows

  rows="$(jq -r '
    to_entries[]
    | select(.value == "baseline_red" or .value == "inconclusive" or .value == "no_measurement")
    | [.key, .value] | @tsv
  ' <<< "${RESULTS_JSON}" 2>/dev/null || true)"

  [ -n "${rows}" ] || return 0

  {
    printf '\n### Differential baseline evidence\n'
    printf -- '- Classification: baseline failures or missing comparable counts prevented candidate regression enforcement.\n'
    if [ -f "${baseline_status_file}" ]; then
      printf -- '- Baseline status artifact: `baseline-status.json`\n'
    fi
  } >> "${digest_file}"

  while IFS=$'\t' read -r command status; do
    [ -n "${command}" ] || continue
    local baseline_command baseline_exit baseline_result candidate_result result_filename baseline_json baseline_log
    baseline_command="$(jq -r --arg cmd "${command}" '.[$cmd].command // ("homeboy " + $cmd)' "${baseline_status_file}" 2>/dev/null || printf 'homeboy %s' "${command}")"
    baseline_exit="$(jq -r --arg cmd "${command}" '.[$cmd].exit_code // "unknown"' "${baseline_status_file}" 2>/dev/null || printf 'unknown')"
    baseline_result="$(jq -r --arg cmd "${command}" '.[$cmd].status // "unknown"' "${baseline_status_file}" 2>/dev/null || printf 'unknown')"
    candidate_result="$(jq -r --arg cmd "${command}" '.[$cmd] // "unknown"' <<< "${RESULTS_JSON}" 2>/dev/null || printf 'unknown')"
    result_filename="$(command_result_filename "${command}")"
    baseline_json="baseline-${result_filename}"
    baseline_log="baseline-${result_filename%.json}.log"

    {
      printf -- '- `%s`: **%s**\n' "${command}" "${status}"
      printf '  - Baseline command: `%s`\n' "${baseline_command}"
      printf '  - Baseline result: `%s` (exit `%s`)\n' "${baseline_result}" "${baseline_exit}"
      printf '  - Candidate result: `%s`\n' "${candidate_result}"
      printf '  - Artifact refs: `%s`, `%s`, `%s`\n' "${result_filename}" "${baseline_json}" "${baseline_log}"
    } >> "${digest_file}"
  done <<< "${rows}"
}

append_terminal_diagnostics() {
  local digest_file="$1"
  local diagnostics=""
  local command diagnostic

  while IFS= read -r command; do
    [ -n "${command}" ] || continue
    while IFS= read -r diagnostic; do
      [ -n "${diagnostic}" ] || continue
      printf '::error::homeboy %s terminal diagnostic: %s\n' "${command}" "${diagnostic}"
      diagnostics+="- \`homeboy ${command}\`: ${diagnostic}"$'\n'
    done < <(observation_terminal_diagnostics "${HOMEBOY_OBSERVATIONS_DIR:-}" "${command}")
  done < <(jq -r 'to_entries[] | select(.value == "fail") | .key' <<< "${RESULTS_JSON}" 2>/dev/null || true)

  [ -n "${diagnostics}" ] || return 0
  {
    printf '\n### Terminal diagnostics\n'
    printf '%s' "${diagnostics}"
  } >> "${digest_file}"
}

append_timeout_triage_to_digest() {
  local digest_file="$1" command json_file
  local timeout_body=""

  while IFS= read -r command; do
    [ -n "${command}" ] || continue
    json_file="${OUTPUT_DIR}/$(command_result_filename "${command}")"
    [ -f "${json_file}" ] || json_file=""
    SECTION_BODY=""
    append_timeout_triage "${command}" "${json_file}"
    timeout_body+="${SECTION_BODY}"
  done < <(jq -r 'to_entries[] | select(.value == "timeout") | .key' <<< "${RESULTS_JSON}" 2>/dev/null || true)

  [ -n "${timeout_body}" ] || return 0
  {
    printf '\n### Timeout triage\n\n'
    printf '%s' "${timeout_body}"
  } >> "${digest_file}"
}

write_fallback_digest() {
  local digest_file="$1"
  local renderer_stderr_file="$2"
  local renderer_status="$3"
  local failed_commands="" diagnostics command status summary

  while IFS=$'\t' read -r command status; do
    [ -n "${command}" ] || continue
    summary="$(jq -r '(.summary // .diagnostics.message // .data.failure.summary // "no summary recorded") | strings' "${OUTPUT_DIR}/$(command_result_filename "${command}")" 2>/dev/null || printf 'no summary recorded')"
    failed_commands+="- \`homeboy ${command}\`: **${status}** - ${summary} (result: \`$(command_result_filename "${command}")\`)"$'\n'
  done < <(jq -r '
    to_entries[]
    | select(.value == "fail" or .value == "timeout")
    | [.key, .value] | @tsv
  ' <<< "${RESULTS_JSON}" 2>/dev/null || true)
  diagnostics="$(tail -c 4000 "${renderer_stderr_file}" 2>/dev/null | python3 "${SCRIPT_DIR}/../pr/comment/sanitize-timeout-diagnostic.py" || true)"

  {
    printf '## Failure digest unavailable\n\n'
    printf 'The Homeboy failure-digest renderer could not produce a digest for the selected Homeboy binary. The original gate failure remains authoritative; per-command result JSON and logs are in the CI results artifact `%s`. This fallback and renderer stderr are in the matching `-failure-digest` artifact.\n\n' "${HOMEBOY_CI_RESULTS_ARTIFACT:-homeboy-ci-results}"
    printf '### Failed commands\n'
    if [ -n "${failed_commands}" ]; then
      printf '%s\n' "${failed_commands}"
    else
      printf '%s\n' '- The final result envelope did not identify a failed command.'
    fi
    printf '\n### Renderer diagnostic\n\n- Status: **%s**\n\n```text\n%s\n```\n' "${renderer_status}" "${diagnostics:-No renderer stderr was captured.}"
  } > "${digest_file}"

  while IFS= read -r command; do
    [ -n "${command}" ] || continue
    printf '::error::homeboy %s failed, but its failure digest was unavailable; inspect the CI results and -failure-digest artifacts.\n' "${command}"
  done < <(jq -r 'to_entries[] | select(.value == "fail" or .value == "timeout") | .key' <<< "${RESULTS_JSON}" 2>/dev/null || true)
  printf '::error::Failure digest renderer could not produce a digest; inspect the CI results and -failure-digest artifacts for original command failures and renderer diagnostics.\n'
}

if [ -z "${OUTPUT_DIR}" ] || [ ! -d "${OUTPUT_DIR}" ]; then
  echo "No output directory available; skipping failure digest"
  exit 0
fi

TOOLING_JSON="${OUTPUT_DIR}/failure-digest-tooling.json"
DIGEST_FILE="${OUTPUT_DIR}/failure-digest.md"
RENDERER_STDERR_FILE="${OUTPUT_DIR}/failure-digest-renderer.stderr"

jq -n \
  --arg homeboy_cli_version "${HOMEBOY_CLI_VERSION}" \
  --arg extension_id "${HOMEBOY_EXTENSION_ID}" \
  --arg extension_source "${HOMEBOY_EXTENSION_SOURCE}" \
  --arg extension_revision "${HOMEBOY_EXTENSION_REVISION}" \
  --arg action_repository "${HOMEBOY_ACTION_REPOSITORY}" \
  --arg action_ref "${HOMEBOY_ACTION_REF}" \
  '{
    homeboy_cli_version: $homeboy_cli_version,
    extension_id: $extension_id,
    extension_source: $extension_source,
    extension_revision: $extension_revision,
    action_repository: $action_repository,
    action_ref: $action_ref
  }' > "${TOOLING_JSON}"

ARGS=(
  runs report failure-digest
  --output-dir "${OUTPUT_DIR}"
  --results "${RESULTS_JSON}"
  --run-url "${RUN_URL}"
  --tooling-json "${TOOLING_JSON}"
  --commands "${COMMANDS_CSV}"
)

rm -f "${DIGEST_FILE}" "${RENDERER_STDERR_FILE}"

if has_setup_failure; then
  : > "${RENDERER_STDERR_FILE}"
  {
    printf '## Setup failure digest\n\n'
    printf 'Action setup did not complete. The requested quality commands were not run.\n'
  } > "${DIGEST_FILE}"
else
  renderer_status="unsupported by selected Homeboy binary"
  if homeboy runs report failure-digest --help > /dev/null 2> "${RENDERER_STDERR_FILE}"; then
    renderer_status="failed"
    if ! homeboy "${ARGS[@]}" > "${DIGEST_FILE}" 2>> "${RENDERER_STDERR_FILE}"; then
      rm -f "${DIGEST_FILE}"
    fi
  fi
fi

if [ ! -s "${DIGEST_FILE}" ]; then
  rm -f "${DIGEST_FILE}"
  write_fallback_digest "${DIGEST_FILE}" "${RENDERER_STDERR_FILE}" "${renderer_status:-failed}"
fi

append_local_reproduction_commands "${DIGEST_FILE}"
append_differential_evidence "${DIGEST_FILE}"
append_terminal_diagnostics "${DIGEST_FILE}"
append_timeout_triage_to_digest "${DIGEST_FILE}"
append_setup_failure "${DIGEST_FILE}"

if [ -n "${GITHUB_ENV:-}" ]; then
  echo "HOMEBOY_FAILURE_DIGEST_FILE=${DIGEST_FILE}" >> "${GITHUB_ENV}"
fi

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo ""
    cat "${DIGEST_FILE}"
  } >> "${GITHUB_STEP_SUMMARY}"
fi

echo "Failure digest generated at ${DIGEST_FILE}"
