#!/usr/bin/env bash

set -euo pipefail

if [ -n "${GITHUB_ACTION_PATH:-}" ] && [ -f "${GITHUB_ACTION_PATH}/scripts/core/lib.sh" ]; then
  source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"
else
  source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/core/lib.sh"
fi

POLICY_PATH="${POLICY_PATH:-}"
POLICY_MERGE="${POLICY_MERGE:-false}"
POLICY_MERGE_METHOD="${POLICY_MERGE_METHOD:-squash}"
PR_NUMBER="${PR_NUMBER:-}"
REPO="${REPOSITORY:-${GITHUB_REPOSITORY:-}}"
GITHUB_REPOSITORY="${GITHUB_REPOSITORY:-${REPO}}"
PR_AUTHOR="${PR_AUTHOR:-}"
PR_HEAD_REF="${PR_HEAD_REF:-}"
PR_HEAD_REPO="${PR_HEAD_REPO:-}"
COMP_ID="$(resolve_component_id 2>/dev/null || basename "${REPO:-unknown}")"
WORKSPACE="$(resolve_workspace 2>/dev/null || pwd)"

write_output() {
  local name="$1"
  local value="$2"

  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    {
      printf '%s<<HOMEBOY_PR_POLICY\n' "${name}"
      printf '%s\n' "${value}"
      printf 'HOMEBOY_PR_POLICY\n'
    } >> "${GITHUB_OUTPUT}"
  fi
}

finish() {
  local safe="$1"
  local merged="$2"
  local reason="$3"
  local report="$4"

  write_output "safe" "${safe}"
  write_output "merged" "${merged}"
  write_output "reason" "${reason}"
  write_output "report" "${report}"
  printf '%s\n' "${report}"
}

fail_closed() {
  local reason="$1"
  finish "false" "false" "${reason}" "$(printf '## PR policy\n\nUnsafe for auto-merge: %s' "${reason}")"
  exit 0
}

if [ -z "${POLICY_PATH}" ]; then
  fail_closed "POLICY_PATH is required"
fi

if [ -z "${PR_NUMBER}" ] || [ -z "${REPO}" ]; then
  fail_closed "PR_NUMBER and repository are required"
fi

# Policy and merge actions are meaningful only while the PR is open. This is a
# typed non-error outcome so a post-merge quality reconciliation remains useful.
if ! pr_is_active; then
  finish "false" "false" "pr_closed" "## PR policy

Skipped: PR is already merged or closed."
  exit 0
fi

MERGE_ARGS=()
if [ "${POLICY_MERGE}" = "true" ]; then
  MERGE_ARGS+=(--merge)
fi

RESULT=""
set +e
RESULT=$(homeboy git pr policy merge "${COMP_ID}" \
  --path "${WORKSPACE}" \
  --policy "${POLICY_PATH}" \
  --number "${PR_NUMBER}" \
  --author "${PR_AUTHOR}" \
  --head "${PR_HEAD_REF}" \
  --head-repo "${PR_HEAD_REPO}" \
  --repository "${REPO}" \
  --merge-method "${POLICY_MERGE_METHOD}" \
  "${MERGE_ARGS[@]}" 2>&1)
STATUS=$?
set -e

if ! printf '%s' "${RESULT}" | jq -e '.data' >/dev/null 2>&1; then
  fail_closed "homeboy git pr policy merge failed: ${RESULT}"
fi

SAFE="$(printf '%s' "${RESULT}" | jq -r '.data.safe // .data.allowed // false')"
MERGED="$(printf '%s' "${RESULT}" | jq -r '.data.merged // false')"
REASON="$(printf '%s' "${RESULT}" | jq -r '.data.reason // "unknown"')"
REPORT="$(printf '%s' "${RESULT}" | jq -r '.data.report // empty')"

finish "${SAFE}" "${MERGED}" "${REASON}" "${REPORT}"

if [ "${STATUS}" -ne 0 ]; then
  exit 0
fi
