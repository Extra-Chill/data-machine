#!/usr/bin/env bash

# Reconcile independently produced candidate and baseline evidence. Artifacts
# are untrusted transport: every identity field is checked before a verdict.
set -euo pipefail

artifact_root="${PHASE_ARTIFACT_ROOT:?PHASE_ARTIFACT_ROOT is required}"
command="${COMMAND:?COMMAND is required}"
artifact_key="${ARTIFACT_KEY:?ARTIFACT_KEY is required}"
action_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck disable=SC1091
source "${action_root}/scripts/core/lib.sh"

fail_closed() {
  echo "::error::$1"
  echo "results={\"${command}\":\"fail\"}" >> "${GITHUB_OUTPUT}"
  exit 1
}

# Selects into RESOLVED_MANIFEST rather than printing to stdout.
#
# This deliberately does NOT return the path via stdout, because callers would
# then have to invoke it in a command substitution — and `fail_closed` writes
# its `::error::` line to stdout, so inside `$( )` the message is captured into
# the caller's variable instead of reaching the log. A missing artifact then
# failed the job silently: exit 1, `results=fail` recorded, and not one word
# explaining why. Keeping this in the current shell keeps the diagnosis visible.
RESOLVED_MANIFEST=""
RESOLVED_ARTIFACT_NAME=""
manifest_for() {
  local phase="$1"
  local path attempt selected="" selected_attempt=0 selected_count=0
  while IFS= read -r path; do
    attempt="$(jq -r '.run_attempt // 0' "${path}" 2>/dev/null || printf '0')"
    [[ "${attempt}" =~ ^[0-9]+$ ]] || attempt=0
    if [ "${attempt}" -gt "${selected_attempt}" ]; then
      selected="${path}"
      selected_attempt="${attempt}"
      selected_count=1
    elif [ "${attempt}" -eq "${selected_attempt}" ] && [ "${attempt}" -gt 0 ]; then
      selected_count=$((selected_count + 1))
    fi
  done < <(find "${artifact_root}" -path "*/${phase}/manifest.json" -type f | sort)
  if [ -z "${selected}" ]; then
    local expected_artifact="homeboy-differential-${phase}-${artifact_key}-${RUN_ATTEMPT}"
    fail_closed "No ${phase} provenance artifact '${expected_artifact}' was found under '${artifact_root}'. The ${phase} producer did not publish valid evidence. Repair that producer, then rerun the complete workflow: gh run rerun ${GITHUB_RUN_ID:-<run-id>} --repo ${REPOSITORY:?REPOSITORY is required}"
  fi
  [ "${selected_count}" -eq 1 ] || fail_closed "Expected one newest ${phase} provenance artifact; found ${selected_count} at attempt ${selected_attempt}."
  RESOLVED_MANIFEST="${selected}"
  local relative="${selected#"${artifact_root}"/}" first_component=""
  first_component="${relative%%/*}"
  if [[ "${first_component}" == homeboy-differential-* ]]; then
    RESOLVED_ARTIFACT_NAME="${first_component}"
  else
    RESOLVED_ARTIFACT_NAME="homeboy-differential-${phase}-${artifact_key}-${selected_attempt}"
  fi
}

manifest_for candidate
candidate_manifest="${RESOLVED_MANIFEST}"
candidate_artifact_name="${RESOLVED_ARTIFACT_NAME}"
candidate_dir="$(dirname "${candidate_manifest}")"

# Report WHICH identity field failed.
#
# This used to be a single twelve-clause jq predicate with `2>/dev/null` and one
# catch-all message, so a mismatch was indistinguishable from a missing file and
# an operator could not tell which field was wrong without reproducing the run.
# Artifacts are untrusted transport, so the checks themselves stay strict — only
# the reporting changes.
_field_mismatch() {
  local phase="$1" artifact_name="$2" field="$3" expected="$4" actual="$5"
  fail_closed "Malformed provenance from ${phase} producer artifact '${artifact_name}': ${phase} phase provenance field '${field}' does not match this workflow: expected '${expected}', artifact has '${actual}'. Repair the ${phase} producer, then rerun the complete workflow: gh run rerun ${GITHUB_RUN_ID:-<run-id>} --repo ${REPOSITORY}"
}

_check_equals() {
  local phase="$1" artifact_name="$2" manifest="$3" field="$4" expected="$5"
  local actual
  actual="$(jq -r --arg f "${field}" '.[$f] // "<absent>"' "${manifest}" 2>/dev/null || printf '<unreadable>')"
  [ "${actual}" = "${expected}" ] || _field_mismatch "${phase}" "${artifact_name}" "${field}" "${expected}" "${actual}"
}

_check_predicate() {
  local phase="$1" artifact_name="$2" manifest="$3" field="$4" description="$5" filter="$6"
  jq -e "${filter}" "${manifest}" >/dev/null 2>&1 && return 0
  local actual
  actual="$(jq -c --arg f "${field}" '.[$f] // "<absent>"' "${manifest}" 2>/dev/null || printf '<unreadable>')"
  fail_closed "Malformed provenance from ${phase} producer artifact '${artifact_name}': ${phase} phase provenance field '${field}' is invalid: ${description}. Artifact has ${actual}. Repair the ${phase} producer, then rerun the complete workflow: gh run rerun ${GITHUB_RUN_ID:-<run-id>} --repo ${REPOSITORY}"
}

validate_manifest() {
  local phase="$1" artifact_name="$2" manifest="$3" expected_sha="$4"

  [ -f "${manifest}" ] || fail_closed "Malformed provenance from ${phase} producer artifact '${artifact_name}': manifest is missing at '${manifest}'."
  jq -e 'type == "object"' "${manifest}" >/dev/null 2>&1 \
    || fail_closed "Malformed provenance from ${phase} producer artifact '${artifact_name}': manifest at '${manifest}' is not a JSON object."

  _check_equals "${phase}" "${artifact_name}" "${manifest}" phase           "${phase}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" repository      "${REPOSITORY:?REPOSITORY is required}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" candidate_sha   "${CANDIDATE_SHA:?CANDIDATE_SHA is required}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" base_sha        "${BASE_SHA:?BASE_SHA is required}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" checkout_sha    "${expected_sha}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" command         "${command}"
  _check_equals "${phase}" "${artifact_name}" "${manifest}" action_revision "${ACTION_REVISION:?ACTION_REVISION is required}"

  _check_predicate "${phase}" "${artifact_name}" "${manifest}" run_attempt \
    "must be a positive number no greater than this run's attempt (${RUN_ATTEMPT})" \
    '(.run_attempt | type == "number" and . > 0 and . <= (env.RUN_ATTEMPT | tonumber))'
  _check_predicate "${phase}" "${artifact_name}" "${manifest}" component \
    'must be a non-empty string' \
    '(.component | type == "string" and length > 0)'
  _check_predicate "${phase}" "${artifact_name}" "${manifest}" cli_revision \
    'must be a non-empty string' \
    '(.cli_revision | type == "string" and length > 0)'
  _check_predicate "${phase}" "${artifact_name}" "${manifest}" binary_sha256 \
    'must be a 64-character hex digest' \
    '(.binary_sha256 | type == "string" and test("^[0-9a-f]{64}$"))'
  _check_predicate "${phase}" "${artifact_name}" "${manifest}" execution_state \
    "must be 'completed', 'pr_closed', or 'pr_merged'" \
    '((.execution_state // "completed") == "completed" or .execution_state == "pr_closed" or .execution_state == "pr_merged")'
  _check_predicate "${phase}" "${artifact_name}" "${manifest}" results \
    "must record '${command}' as pass, fail, or timeout after execution, or be empty for a terminal PR state" \
    "(.results | type == \"object\") and (if .execution_state == \"pr_closed\" or .execution_state == \"pr_merged\" then (.results | length == 0) else (.[\"results\"][\"${command}\"] == \"pass\" or .[\"results\"][\"${command}\"] == \"fail\" or .[\"results\"][\"${command}\"] == \"timeout\") end)"
}

# Only an open PR can contradict recorded terminal PR evidence. Re-read the
# canonical state when the finalizer did not supply one, because `active=true`
# is intentionally also used for an unreadable, fail-open probe.
assert_pr_not_active() {
  local phase="$1" execution_state="$2" observed="${PR_STATE:-}"
  if [ "${observed}" != open ] && [ "${observed}" != closed ] && [ "${observed}" != merged ]; then
    # Keep the legacy boolean contract for direct consumers that have not yet
    # supplied PR_STATE; workflow callers always provide the typed state.
    [ "${PR_ACTIVE:-}" = false ] && observed=closed || observed="$(pr_lifecycle_state)"
  fi
  case "${observed}" in
    closed|merged)
      echo "::warning::Final PR state is ${observed} (pr=${PR_NUMBER:-unknown}); accepting recorded ${execution_state} evidence from ${phase}."
      ;;
    open)
      fail_closed "$(printf '%s command execution was skipped as %s while the pull request is open. pr=%s final_state=%s reported_pr_active=%s' \
        "${phase}" "${execution_state}" "${PR_NUMBER:-unknown}" "${observed}" "${PR_ACTIVE:-<unset>}")"
      ;;
    unknown)
      fail_closed "$(printf '%s command execution was skipped as %s, but the final PR state could not be determined. pr=%s reported_pr_active=%s' \
        "${phase}" "${execution_state}" "${PR_NUMBER:-unknown}" "${PR_ACTIVE:-<unset>}")"
      ;;
  esac
}

validate_manifest candidate "${candidate_artifact_name}" "${candidate_manifest}" "${CANDIDATE_SHA}"
candidate_execution_state="$(jq -r '.execution_state // "completed"' "${candidate_manifest}")"
if [ "${candidate_execution_state}" = pr_closed ] || [ "${candidate_execution_state}" = pr_merged ]; then
  assert_pr_not_active Candidate "${candidate_execution_state}"
  printf 'results={}\ncomponent=%s\noutput-dir=%s\nlifecycle-state=%s\n' \
    "$(jq -r '.component' "${candidate_manifest}")" "${candidate_dir}/homeboy-ci-results" "${candidate_execution_state}" >> "${GITHUB_OUTPUT}"
  echo "PR terminal before candidate command execution; recording a neutral ${candidate_execution_state} lifecycle verdict."
  exit 0
fi
candidate_results="$(jq -c '.results' "${candidate_manifest}")"
candidate_output="${candidate_dir}/homeboy-ci-results"
[ -d "${candidate_output}" ] || fail_closed "Candidate result payload is missing."

require_baseline=false
case "${REQUIRE_BASELINE:-false}" in
  true) require_baseline=true ;;
  candidate-failure)
    candidate_status="$(jq -r --arg command "${command}" '.[$command]' <<< "${candidate_results}")"
    [ "${candidate_status}" = pass ] || require_baseline=true
    ;;
  false) ;;
  *) fail_closed "Unknown baseline requirement policy '${REQUIRE_BASELINE}'." ;;
esac

if [ "${require_baseline}" = "true" ]; then
  manifest_for baseline
  baseline_manifest="${RESOLVED_MANIFEST}"
  baseline_artifact_name="${RESOLVED_ARTIFACT_NAME}"
  baseline_dir="$(dirname "${baseline_manifest}")"
  validate_manifest baseline "${baseline_artifact_name}" "${baseline_manifest}" "${BASE_SHA}"
  baseline_execution_state="$(jq -r '.execution_state // "completed"' "${baseline_manifest}")"
  if [ "${baseline_execution_state}" = pr_closed ] || [ "${baseline_execution_state}" = pr_merged ]; then
    assert_pr_not_active Baseline "${baseline_execution_state}"
    printf 'results={}\ncomponent=%s\noutput-dir=%s\nlifecycle-state=%s\n' \
      "$(jq -r '.component' "${candidate_manifest}")" "${candidate_output}" "${baseline_execution_state}" >> "${GITHUB_OUTPUT}"
    echo "PR terminal before baseline command execution; recording a neutral ${baseline_execution_state} lifecycle verdict."
    exit 0
  fi
  candidate_component="$(jq -r '.component' "${candidate_manifest}")"
  baseline_component="$(jq -r '.component' "${baseline_manifest}")"
  candidate_cli="$(jq -r '.cli_revision' "${candidate_manifest}")"
  baseline_cli="$(jq -r '.cli_revision' "${baseline_manifest}")"
  candidate_binary="$(jq -r '.binary_sha256' "${candidate_manifest}")"
  baseline_binary="$(jq -r '.binary_sha256' "${baseline_manifest}")"
  [ "${candidate_component}" = "${baseline_component}" ] || fail_closed "Candidate and baseline component provenance differs."
  [ "${candidate_cli}" = "${baseline_cli}" ] || fail_closed "Candidate and baseline CLI provenance differs."
  [ "${candidate_binary}" = "${baseline_binary}" ] || fail_closed "Candidate and baseline binary provenance differs."
  baseline_results="$(jq -c '.results' "${baseline_manifest}")"
  baseline_output="${baseline_dir}/homeboy-ci-results"
  [ -d "${baseline_output}" ] || fail_closed "Baseline result payload is missing."

  for baseline_file in "${baseline_output}"/*.json; do
    [ -f "${baseline_file}" ] || continue
    name="$(basename "${baseline_file}")"
    if [ "${name}" = "baseline-status.json" ]; then
      cp "${baseline_file}" "${candidate_output}/${name}"
    else
      cp "${baseline_file}" "${candidate_output}/baseline-${name}"
    fi
  done

# apply-differential-gate expects baseline command metadata beside its payload.
baseline_status="${baseline_output}/baseline-status.json"
if [ ! -f "${baseline_status}" ]; then
  baseline_status_value="$(jq -r --arg command "${command}" '.[$command]' <<< "${baseline_results}")"
  structured=false
  stem="$(printf '%s' "${command}" | tr -c 'A-Za-z0-9._-' '-')"
  [ -f "${baseline_output}/${stem}.json" ] && structured=true
  jq -cn --arg command "${command}" --arg status "${baseline_status_value}" --argjson structured "${structured}" \
    '{($command): {status:$status, exit_code:(if $status == "pass" then 0 elif $status == "timeout" then 124 else 1 end), command:$command, structured_output:$structured}}' > "${baseline_status}"
fi
fi

if [ "${require_baseline}" = "true" ]; then
  results="$(python3 "${action_root}/scripts/core/apply-differential-gate.py" "${candidate_results}" "${candidate_output}" "${baseline_output}")"
else
  results="${candidate_results}"
fi

{
  echo 'results<<__HOMEBOY_RESULTS__'
  printf '%s\n' "${results}"
  echo '__HOMEBOY_RESULTS__'
} >> "${GITHUB_OUTPUT}"

component="$(jq -r '.component' "${candidate_manifest}")"
printf 'component=%s\noutput-dir=%s\n' "${component}" "${candidate_output}" >> "${GITHUB_OUTPUT}"

RESULTS="${results}" COMMANDS="${command}" OPERATIONS_RESULTS='' PR_ACTIVE='' \
  bash "${action_root}/scripts/core/enforce-final-status.sh"
