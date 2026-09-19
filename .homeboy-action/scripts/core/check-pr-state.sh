#!/usr/bin/env bash

# Report whether the pull request remains eligible for PR-only publication.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/lib.sh"

state="$(pr_lifecycle_state)"
case "${state}" in
  open) printf 'active=true\nreason=active\nstate=open\n' >> "${GITHUB_OUTPUT}" ;;
  closed|merged)
    echo "::warning::PR #${PR_NUMBER:-unknown} is ${state} - skipping PR-only publication"
    printf 'active=false\nreason=pr_%s\nstate=%s\n' "${state}" "${state}" >> "${GITHUB_OUTPUT}"
    ;;
  unknown)
    echo "::warning::Could not determine PR #${PR_NUMBER:-unknown} lifecycle state - preserving PR-only work"
    printf 'active=true\nreason=unknown\nstate=unknown\n' >> "${GITHUB_OUTPUT}"
    ;;
esac
