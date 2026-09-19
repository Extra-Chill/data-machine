#!/usr/bin/env bash

# Materialize only schema-valid provenance. A phase artifact is a producer
# contract, not a best-effort diagnostic assembled from optional action outputs.
set -euo pipefail

phase="${PHASE:-unknown}"
artifact_name="${PHASE_ARTIFACT_NAME:-}"
repository="${REPOSITORY:-}"
run_id="${GITHUB_RUN_ID:-<run-id>}"
output_dir="${PHASE_OUTPUT_DIR:-differential-phase/${phase}}"

publication_error() {
  local field="$1" description="$2" value="$3"
  local actual
  actual="$(jq -Rn --arg value "${value}" '$value')"
  echo "::error::Cannot publish ${phase} differential provenance artifact '${artifact_name:-unknown}': field '${field}' ${description}. Value: ${actual}. Repair the ${phase} producer, then rerun the complete workflow: gh run rerun ${run_id} --repo ${repository:-<owner/repo>}"
  exit 1
}

require_non_empty() {
  local field="$1" value="$2"
  [ -n "${value}" ] || publication_error "${field}" 'must be a non-empty string' "${value}"
}

case "${phase}" in
  candidate|baseline) ;;
  *) publication_error phase "must be 'candidate' or 'baseline'" "${phase}" ;;
esac

require_non_empty artifact_name "${artifact_name}"
require_non_empty repository "${repository}"
require_non_empty candidate_sha "${CANDIDATE_SHA:-}"
require_non_empty base_sha "${BASE_SHA:-}"
require_non_empty checkout_sha "${CHECKOUT_SHA:-}"
require_non_empty command "${COMMAND:-}"
require_non_empty component "${COMPONENT:-}"
require_non_empty action_revision "${ACTION_REVISION:-}"
require_non_empty cli_revision "${CLI_REVISION:-}"

if ! [[ "${BINARY_SHA256:-}" =~ ^[0-9a-f]{64}$ ]]; then
  publication_error binary_sha256 'must be a 64-character lowercase hex digest' "${BINARY_SHA256:-}"
fi
if ! [[ "${RUN_ATTEMPT:-}" =~ ^[1-9][0-9]*$ ]]; then
  publication_error run_attempt 'must be a positive integer' "${RUN_ATTEMPT:-}"
fi

results="${RESULTS:-}"
[ -n "${results}" ] || results='{}'
execution_state="${EXECUTION_STATE:-completed}"
case "${execution_state}" in
  completed)
    if ! jq -e --arg command "${COMMAND}" \
      'type == "object" and (.[$command] == "pass" or .[$command] == "fail" or .[$command] == "timeout")' \
      <<< "${results}" >/dev/null 2>&1; then
      publication_error results "must be an object recording '${COMMAND}' as pass, fail, or timeout" "${results}"
    fi
    ;;
  pr_closed|pr_merged)
    results='{}'
    ;;
  *) publication_error execution_state "must be 'completed', 'pr_closed', or 'pr_merged'" "${execution_state}" ;;
esac

mkdir -p "${output_dir}"
manifest="${output_dir}/manifest.json"
temporary="${manifest}.tmp"
trap 'rm -f "${temporary}"' EXIT
jq -cn \
  --arg phase "${phase}" \
  --arg repository "${repository}" \
  --arg candidate_sha "${CANDIDATE_SHA}" \
  --arg base_sha "${BASE_SHA}" \
  --arg checkout_sha "${CHECKOUT_SHA}" \
  --arg command "${COMMAND}" \
  --arg component "${COMPONENT}" \
  --arg action_revision "${ACTION_REVISION}" \
  --arg cli_revision "${CLI_REVISION}" \
  --arg binary_sha256 "${BINARY_SHA256}" \
  --arg execution_state "${execution_state}" \
  --argjson run_attempt "${RUN_ATTEMPT}" \
  --argjson results "${results}" \
  '{phase:$phase,repository:$repository,candidate_sha:$candidate_sha,base_sha:$base_sha,checkout_sha:$checkout_sha,command:$command,component:$component,action_revision:$action_revision,cli_revision:$cli_revision,binary_sha256:$binary_sha256,execution_state:$execution_state,run_attempt:$run_attempt,results:$results}' \
  > "${temporary}"
mv "${temporary}" "${manifest}"
echo "Validated ${phase} differential provenance for artifact '${artifact_name}'."
