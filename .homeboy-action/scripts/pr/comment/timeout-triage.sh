#!/usr/bin/env bash

# Timeout evidence is deliberately projected from durable result and phase
# artifacts. Never render recipe payloads: they can be large and may contain
# credentials or source snippets.

timeout_phase_progress_file() {
  printf '%s\n' "${HOMEBOY_ACTION_PHASE_PROGRESS_FILE:-${RUNNER_TEMP:-/tmp}/homeboy-action-phase-progress-${GITHUB_RUN_ID:-local}-${GITHUB_JOB:-local}.jsonl}"
}

sanitize_timeout_diagnostic() {
  local sanitizer
  sanitizer="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sanitize-timeout-diagnostic.py"
  python3 "${sanitizer}"
}

timeout_safe_phase() {
  local value="$1"
  if [[ "${value}" =~ ^[A-Za-z0-9._-]{1,80}$ ]]; then
    printf '%s\n' "${value}"
  else
    printf '%s\n' 'unknown'
  fi
}

timeout_safe_uint() {
  local value="$1"
  if [[ "${value}" =~ ^[0-9]{1,10}$ ]] && [ "$((10#${value}))" -le 1000000000 ]; then
    printf '%s\n' "${value}"
  else
    printf '%s\n' 'unknown'
  fi
}

timeout_import_command() {
  local component repository artifact run_id
  component="${COMP_ID:-${COMPONENT_NAME:-}}"
  repository="${GITHUB_REPOSITORY:-}"
  artifact="${HOMEBOY_OBSERVATIONS_ARTIFACT:-}"
  run_id="${GITHUB_RUN_ID:-}"

  [[ "${component}" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || return 1
  [[ "${repository}" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || return 1
  [[ "${artifact}" =~ ^[A-Za-z0-9._*-]{1,180}$ ]] || return 1
  [[ "${run_id}" =~ ^[0-9]{1,20}$ ]] || return 1

  printf "homeboy runs import --from-gh-actions --component %s --repo %s --artifact-glob '%s' --run-id %s\n" \
    "${component}" "${repository}" "${artifact}" "${run_id}"
}

timeout_fallback_budget() {
  if [ "$(quality_base_command "$1")" = "test" ]; then
    printf '%s\n' "${HOMEBOY_TEST_TIMEOUT_SECONDS:-unknown}"
  else
    printf '%s\n' "${HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS:-unknown}"
  fi
}

append_timeout_triage() {
  local command="$1" json_file="$2" phase_file phase elapsed budget selected counts diagnostic
  local run_url="${GITHUB_RUN_URL:-}" pr_url="${GITHUB_PR_URL:-}"

  phase_file="$(timeout_phase_progress_file)"
  phase="command_execution"
  elapsed="unknown"
  budget="$(timeout_fallback_budget "${command}")"
  selected="unknown"
  counts="incomplete (test counts absent)"
  diagnostic="No bounded semantic diagnostic was recorded. Inspect the linked CI results artifact."

  if [ -n "${json_file}" ] && [ -f "${json_file}" ]; then
    phase="$(jq -r '.data.timeout.phase // .data.failure.phase // .data.phase // "command_execution"' "${json_file}" 2>/dev/null || printf 'command_execution')"
    elapsed="$(jq -r '.data.timeout.elapsed_seconds // .data.elapsed_seconds // .elapsed_seconds // empty' "${json_file}" 2>/dev/null || true)"
    budget="$(jq -r --arg fallback "${budget}" '.data.timeout.budget_seconds // .data.timeout.timeout_seconds // .data.budget_seconds // .budget_seconds // $fallback' "${json_file}" 2>/dev/null || printf '%s' "${budget}")"
    selected="$(jq -r '
      .data.selected_count // .data.selection.selected_count // .data.recipe_run.selected_count
      // (.data.selected_files? | if type == "array" then length else empty end)
      // empty
    ' "${json_file}" 2>/dev/null || true)"
    counts="$(jq -r '
      (.data.test_counts // .test_counts) as $counts
      | if ($counts | type) != "object" then "incomplete (test counts absent)"
        elif ([$counts.passed, $counts.failed, $counts.skipped, $counts.total] | all(type == "number" and . >= 0 and . <= 1000000000 and floor == .)) then
          if $counts.total == 0 then "complete, zero totals"
          else "complete: " + ($counts.passed|tostring) + " passed, " + ($counts.failed|tostring) + " failed, " + ($counts.skipped|tostring) + " skipped of " + ($counts.total|tostring)
          end
        else "incomplete (test counts missing fields)"
        end
    ' "${json_file}" 2>/dev/null || printf 'incomplete (test counts unreadable)')"
    diagnostic="$(jq -r '
      [ .data.recipe_run.semantic_diagnostic, .data.semantic_diagnostic,
        .data.failure.message, .data.raw_output.stderr_tail, .error, .message ]
      | map(select(type == "string" and length > 0)) | first // empty
      | .[0:16384]
    ' "${json_file}" 2>/dev/null | sanitize_timeout_diagnostic || true)"
    [ -n "${diagnostic}" ] || diagnostic="No bounded semantic diagnostic was recorded. Inspect the linked CI results artifact."
  fi
  phase="$(timeout_safe_phase "${phase}")"
  elapsed="$(timeout_safe_uint "${elapsed}")"
  budget="$(timeout_safe_uint "${budget}")"
  selected="$(timeout_safe_uint "${selected}")"

  if [ "${elapsed}" = "unknown" ] && [ -f "${phase_file}" ]; then
    elapsed="$(jq -sr '[.[] | select(.phase == "command_execution" and .event == "completed") | .elapsed_seconds] | last // empty' "${phase_file}" 2>/dev/null || true)"
    elapsed="$(timeout_safe_uint "${elapsed}")"
  fi

  SECTION_BODY+="#### Timeout triage"$'\n\n'
  SECTION_BODY+="- Phase: \`${phase}\`; elapsed/budget: \`${elapsed}s / ${budget}s\`"$'\n'
  SECTION_BODY+="- Selected: \`${selected}\`; counts: ${counts}"$'\n'
  SECTION_BODY+="> ${diagnostic}"$'\n'
  if [ -n "${run_url}" ]; then
    SECTION_BODY+="- [CI results artifact: \`${HOMEBOY_CI_RESULTS_ARTIFACT:-homeboy-ci-results}\`](${run_url})"$'\n'
    SECTION_BODY+="- [Observation artifact: \`${HOMEBOY_OBSERVATIONS_ARTIFACT:-homeboy-observations}\`](${run_url})"$'\n'
  fi
  if [ -n "${pr_url}" ]; then
    SECTION_BODY+="- \`homeboy review ci triage ${pr_url}\`"$'\n'
  fi
  local import_command
  import_command="$(timeout_import_command || true)"
  if [ -n "${import_command}" ]; then
    SECTION_BODY+="- \`${import_command}\`"$'\n'
  fi
  SECTION_BODY+=$'\n'
}
