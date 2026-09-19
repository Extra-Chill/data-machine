#!/usr/bin/env bash

# Post a section to the shared Homeboy Results PR comment.
#
# Uses the sectioned PR-comment primitive from Extra-Chill/homeboy#1353:
#   homeboy git pr comment <component> \
#     --comment-key <outer> --section-key <inner> \
#     --body-file <path> --header "..." --footer-file <path> \
#     --section-order <...>
#
# Core handles:
#   - parsing existing sections (new + legacy markers)
#   - merging this invocation's section in place (preserving position)
#   - race consolidation (canonical = lowest id, delete duplicates)
#   - idempotency (identical body → noop, no PATCH)
#   - header/footer preservation and replacement across merges
#
# This script delegates this job's section body to
# `homeboy review --report=pr-comment`, then lets Homeboy core merge the section
# and footer into the shared sticky comment.
#
# Renderer migration tracked in: Extra-Chill/homeboy-action#239.

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/core/lib.sh"
source "${GITHUB_ACTION_PATH}/scripts/pr/comment/lib.sh"
source "${GITHUB_ACTION_PATH}/scripts/pr/comment/sections.sh"

# Same precedence as select-final-results.sh, generate-failure-digest.sh, and
# auto-file-categorized-issues.sh. This consumer was the one left on the raw
# HOMEBOY_OUTPUT_DIR, which is why dropping the duplicate bench copy broke the
# comment summary rather than any of the other three.
OUTPUT_DIR="${HOMEBOY_CI_RESULTS_DIR:-${HOMEBOY_OUTPUT_DIR:-}}"
COMP_ID="$(resolve_component_id)"
WORKSPACE="$(resolve_workspace)"
REPO="${GITHUB_REPOSITORY}"

if [ -z "${OUTPUT_DIR}" ] || [ -z "${PR_NUMBER:-}" ]; then
  echo "Skipping PR comment — missing output dir or PR number"
  exit 0
fi

if [ -z "${GH_TOKEN:-}" ]; then
  echo "::warning::Skipping PR comment — app-token was not provided. Refusing to post as github-actions[bot]; configure the homeboy-ci GitHub App token."
  exit 0
fi

if ! pr_is_active; then
  echo "Skipping PR comment — PR #${PR_NUMBER} is no longer open (merged or closed)"
  exit 0
fi

DIGEST_FILE="${HOMEBOY_FAILURE_DIGEST_FILE:-}"
COMMENT_KEY="$(derive_comment_key)"
SECTION_KEY="$(derive_section_key)"
SECTION_TITLE="$(derive_section_title)"
HEADER="## Homeboy Results — \`${COMP_ID}\`"
# Preserve the current rendered section order (lint, build, test, audit, bench).
# Tooling versions are passed as the core-managed footer instead of an action-
# rendered pseudo-section.
SECTION_ORDER="lint,build,test,audit,bench"

if ! comment_has_actionable_content; then
  echo "Skipping PR comment — run passed with no actionable content"
  exit 0
fi

build_section_body

SECTION_FILE="$(mktemp)"
TOOLING_FOOTER_FILE="$(mktemp)"
trap 'rm -f "${SECTION_FILE}" "${TOOLING_FOOTER_FILE}"' EXIT

printf '%s' "${SECTION_BODY}" > "${SECTION_FILE}"
build_tooling_footer > "${TOOLING_FOOTER_FILE}"

# --- Post this job's section -------------------------------------------------
POST_RESULT="$(
  homeboy git pr comment "${COMP_ID}" \
    --path "${WORKSPACE}" \
    --number "${PR_NUMBER}" \
    --comment-key "${COMMENT_KEY}" \
    --section-key "${SECTION_KEY}" \
    --body-file "${SECTION_FILE}" \
    --header "${HEADER}" \
    --footer-file "${TOOLING_FOOTER_FILE}" \
    --section-order "${SECTION_ORDER}" 2>/dev/null || true
)"

if [ -z "${POST_RESULT}" ]; then
  # Most common cause: restricted GITHUB_TOKEN on fork PRs. Warn, continue.
  echo "::warning::Could not post PR comment section '${SECTION_KEY}' (likely restricted token for fork PR)."
  exit 0
fi

POSTED_COMMENT_ID="$(printf '%s' "${POST_RESULT}" | jq -r '.data.comment_id // empty' 2>/dev/null || true)"
POSTED_ACTION="$(printf '%s' "${POST_RESULT}" | jq -r '.action // empty' 2>/dev/null || true)"

echo "Posted section '${SECTION_KEY}' to comment #${POSTED_COMMENT_ID:-?} (${POSTED_ACTION:-unknown})"

if [ -n "${POSTED_COMMENT_ID:-}" ]; then
  echo "HOMEBOY_PR_COMMENT_POSTED=true" >> "${GITHUB_ENV}"
  echo "HOMEBOY_PR_COMMENT_ID=${POSTED_COMMENT_ID}" >> "${GITHUB_ENV}"
fi

echo "PR comment posted successfully"
