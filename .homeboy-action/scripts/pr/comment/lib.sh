#!/usr/bin/env bash

set -euo pipefail

derive_comment_key() {
  if [ -n "${COMMENT_KEY_INPUT:-}" ]; then
    printf '%s\n' "${COMMENT_KEY_INPUT}"
  else
    printf '%s\n' "${GITHUB_WORKFLOW:-homeboy}:${COMP_ID}"
  fi
}

derive_section_key() {
  local command_count raw_key
  command_count=$(python3 - <<'PY'
import os
commands = [part.strip() for part in os.environ.get("COMMANDS", "").split(",") if part.strip()]
print(len(commands))
PY
)

  if [ -n "${COMMENT_SECTION_KEY_INPUT:-}" ]; then
    raw_key="${COMMENT_SECTION_KEY_INPUT}"
  elif [ "${command_count}" = "1" ]; then
    raw_key="$(python3 - <<'PY'
import os
commands = [part.strip() for part in os.environ.get("COMMANDS", "").split(",") if part.strip()]
print(commands[0] if commands else os.environ.get("GITHUB_JOB", "homeboy"))
PY
    )"
  else
    raw_key="${GITHUB_JOB:-homeboy}"
  fi

  SECTION_KEY_RAW="${raw_key}" python3 - <<'PY'
import os
import re

raw = os.environ.get("SECTION_KEY_RAW", "homeboy")
slug = re.sub(r"[^A-Za-z0-9._-]+", "-", raw.strip().lower())
slug = re.sub(r"-+", "-", slug).strip("-._")
print(slug or "homeboy")
PY
}

derive_section_title() {
  if [ -n "${COMMENT_SECTION_TITLE_INPUT:-}" ]; then
    printf '%s\n' "${COMMENT_SECTION_TITLE_INPUT}"
  else
    python3 - <<'PY'
import os

provided = os.environ.get("COMMENT_SECTION_KEY_INPUT", "").strip()
commands = [part.strip() for part in os.environ.get("COMMANDS", "").split(",") if part.strip()]
if provided:
    raw = provided
elif len(commands) == 1:
    raw = commands[0]
else:
    raw = os.environ.get("GITHUB_JOB", "homeboy")

words = [word for word in raw.replace("_", "-").split("-") if word]
if not words:
    print("Homeboy")
else:
    print(" ".join(word[:1].upper() + word[1:] for word in words))
PY
  fi
}

summary_json_for_command() {
  # Resolve the structured --output JSON written by run-homeboy-commands.sh.
  # All commands use the same output stem convention.
  local command="$1"
  local stem
  stem="$(printf '%s' "${command}" | sed -E 's/[^[:alnum:]._-]+/-/g; s/^-+//; s/-+$//')"
  if [ -n "${OUTPUT_DIR:-}" ] && [ -f "${OUTPUT_DIR}/${stem}.json" ]; then
    printf '%s\n' "${OUTPUT_DIR}/${stem}.json"
  else
    printf '\n'
  fi
}

command_status() {
  local command="$1"
  echo "${RESULTS}" | jq -r --arg cmd "${command}" 'if .[$cmd] == "pass" or .[$cmd] == "fail" or .[$cmd] == "timeout" or .[$cmd] == "not_run" or .[$cmd] == "baseline_red" or .[$cmd] == "inconclusive" or .[$cmd] == "no_measurement" or .[$cmd] == "invalid_evidence" or .[$cmd] == "no_comparable_evidence" then .[$cmd] else "unknown" end' 2>/dev/null || echo "unknown"
}

is_refactor_owning_section() {
  local section_key="${SECTION_KEY:-}"
  local section_title="${SECTION_TITLE:-}"
  local commands_csv="${COMMANDS:-}"

  case "${section_key}" in
    refactor*|auto-refactor*|*refactor*)
      return 0
      ;;
  esac

  case "${section_title}" in
    Refactor*|Auto-refactor*|*Refactor*)
      return 0
      ;;
  esac

  case "${commands_csv}" in
    *refactor*)
      return 0
      ;;
  esac

  return 1
}

command_is_selected() {
  local command="$1"
  local selected

  IFS=',' read -ra selected <<< "${COMMANDS:-}"
  for item in "${selected[@]}"; do
    item="$(echo "${item}" | xargs)"
    if [ "${item}" = "${command}" ]; then
      return 0
    fi
  done

  return 1
}

commands_have_actionable_status() {
  local selected command status

  IFS=',' read -ra selected <<< "${COMMANDS:-}"
  for command in "${selected[@]}"; do
    command="$(echo "${command}" | xargs)"
    [ -n "${command}" ] || continue

    status="$(command_status "${command}")"
    if [ "${status}" != "pass" ]; then
      return 0
    fi
  done

  return 1
}

scope_has_actionable_content() {
  if is_scoped; then
    return 0
  fi

  if command_is_selected "test" && [ "${TEST_SCOPE_EFFECTIVE:-}" = "changed" ]; then
    return 0
  fi

  return 1
}

outputs_have_actionable_content() {
  local selected command json_file

  IFS=',' read -ra selected <<< "${COMMANDS:-}"
  for command in "${selected[@]}"; do
    command="$(echo "${command}" | xargs)"
    [ -n "${command}" ] || continue

    case "${command}" in
      bench|release|coverage)
        return 0
        ;;
    esac

    json_file="$(summary_json_for_command "${command}")"
    if [ "${command}" = "bench" ] && [ -n "${json_file}" ] && [ -f "${json_file}" ]; then
      return 0
    fi
  done

  if [ -n "${DIGEST_FILE:-}" ] && [ -f "${DIGEST_FILE}" ]; then
    return 0
  fi

  return 1
}

comment_has_actionable_content() {
  if commands_have_actionable_status; then
    return 0
  fi

  if [ "${BINARY_SOURCE:-source}" = "fallback" ]; then
    return 0
  fi

  if scope_has_actionable_content; then
    return 0
  fi

  if outputs_have_actionable_content; then
    return 0
  fi

  return 1
}
