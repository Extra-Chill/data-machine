#!/usr/bin/env bash

# Pure functions for scope queries. Source this file — do not execute directly.
# Reads SCOPE_* env vars set by resolve.sh.

# Is the current run scoped to changed files?
# Returns exit 0 (true) or 1 (false).
is_scoped() {
  [ "${SCOPE_MODE:-full}" = "changed" ]
}

# Is this a fork PR?
# Returns exit 0 (true) or 1 (false).
is_fork() {
  [ "${SCOPE_IS_FORK:-false}" = "true" ]
}

# What context are we in?
# Prints: pr | push | cron | manual
scope_context() {
  printf '%s\n' "${SCOPE_CONTEXT:-manual}"
}

# What's the base ref for diffing? (empty if not scoped)
scope_base_ref() {
  printf '%s\n' "${SCOPE_BASE_REF:-}"
}

# Human-readable scope note for a command (for PR comments).
# Prints "_(changed files only)_" or "".
scope_note_for() {
  local cmd="$1"

  if ! is_scoped; then
    return
  fi

  # All scoped commands get the same note
  case "${cmd}" in
    audit|lint|test)
      printf ' _(changed files only)_'
      ;;
  esac
}
