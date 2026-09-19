#!/usr/bin/env bash

# Pure functions for CLI flag generation based on current scope.
# Source this file — do not execute directly.
# Reads SCOPE_* env vars set by resolve.sh.

# Get CLI flags for a command based on current scope.
# Usage: scope_flags_for "review lint"
#        scope_flags_for "refactor --from all --write"
# Prints: "--changed-since abc123" or ""
scope_flags_for() {
  local cmd="$1"
  local base_cmd

  if declare -F quality_base_command >/dev/null 2>&1; then
    base_cmd="$(quality_base_command "${cmd}")"
  else
    # Extract the base command (first word) from compound commands like "refactor --from all --write"
    base_cmd=$(printf '%s' "${cmd}" | awk '{print $1}')
  fi

  if [ "${SCOPE_MODE:-full}" != "changed" ] || [ -z "${SCOPE_BASE_REF:-}" ]; then
    return
  fi

  case "${base_cmd}" in
    # `audit` was in the exempt arm above, so under differential gating it ran
    # UNSCOPED over the whole repository. Measured on Extra-Chill/homeboy: 12m07s
    # to report `DRIFT INCREASED: 445 new finding(s) since baseline`, exit 1,
    # then a 684s baseline rerun that failed identically, so the gate excused it
    # as `baseline_red` and published green. 32.7 minutes for no verdict
    # (homeboy#11751 W1-2).
    #
    # A differential baseline is not a substitute for scoping here: `review
    # audit` already computes introduced-versus-contextual findings from
    # `--changed-since` itself, which answers "did this PR add a finding" without
    # rerunning the whole audit at the base ref.
    #
    # `audit` and `test` also appeared in the arm below, where they were dead:
    # `case` takes the first matching arm, so those patterns never applied.
    audit|lint|test|refactor|review)
      printf '%s' "--changed-since ${SCOPE_BASE_REF}"
      ;;
    # release, fleet, deploy, and other commands are never scoped
  esac
}
