#!/usr/bin/env bash

set -euo pipefail

source "${GITHUB_ACTION_PATH}/scripts/scope/context.sh"
source "${GITHUB_ACTION_PATH}/scripts/pr/comment/lib.sh"
source "${GITHUB_ACTION_PATH}/scripts/pr/comment/timeout-triage.sh"

commands_use_review_report() {
  local normalized
  normalized="$(canonicalize_commands "${COMMANDS}")"

  local setup_file="${HOMEBOY_SETUP_RESULT_FILE:-${OUTPUT_DIR:+${OUTPUT_DIR}/setup.json}}"
  [ "${normalized}" = "review audit,review lint,review test" ] && [ -z "${EXTRA_ARGS:-}" ] && { [ -z "${setup_file}" ] || [ ! -f "${setup_file}" ]; }
}

add_review_banner() {
  local key="$1"
  local value="$2"

  [ -n "${value}" ] || return 0
  REVIEW_CMD+=(--banner "${key}=${value}")
}

append_review_banner_args() {
  if [ "${BINARY_SOURCE:-source}" = "fallback" ]; then
    add_review_banner "binary-source" "fallback release binary (source build failed)"
  fi
}

append_review_report_section() {
  if ! commands_use_review_report; then
    append_split_command_sections
    return 0
  fi

  local review_md review_exit review_cmd
  local -a REVIEW_CMD
  review_cmd="$(build_review_report_command "${COMP_ID}" "${WORKSPACE}")"
  # Command fragments are generated internally from action inputs.
  # shellcheck disable=SC2206
  REVIEW_CMD=(${review_cmd})

  append_review_banner_args

  set +e
  review_md="$("${REVIEW_CMD[@]}" 2>/dev/null)"
  review_exit=$?
  set -e

  if [ -z "${review_md}" ]; then
    SECTION_BODY+="> :warning: \`homeboy review --report=pr-comment\` produced no output. Check the action logs for details."$'\n\n'
    return 0
  fi

  SECTION_BODY+="${review_md}"$'\n\n'

  # Exit code 1 means the review found issues, which is exactly when the PR
  # comment is most useful. Exit code >=2 is an execution problem; keep the
  # rendered diagnostics if core emitted any, but surface a workflow warning.
  if [ "${review_exit}" -ge 2 ]; then
    echo "::warning::homeboy review report command exited ${review_exit}; posted rendered diagnostics."
  fi

  return 0
}

status_icon() {
  case "$1" in
    pass|passed) printf '%s\n' ':white_check_mark:' ;;
    fail|failed) printf '%s\n' ':x:' ;;
    # Distinct from :x: on purpose. A reader scanning section icons must be
    # able to tell "this change broke tests" from "the suite never finished"
    # without opening the run.
    timeout) printf '%s\n' ':hourglass_flowing_sand:' ;;
    baseline_red|inconclusive|no_measurement) printf '%s\n' ':warning:' ;;
    invalid_evidence|no_comparable_evidence) printf '%s\n' ':x:' ;;
    not_run) printf '%s\n' ':fast_forward:' ;;
    skipped) printf '%s\n' ':fast_forward:' ;;
    *) printf '%s\n' ':warning:' ;;
  esac
}

status_label() {
  case "$1" in
    pass|passed) printf '%s\n' 'passed' ;;
    fail|failed) printf '%s\n' 'failed' ;;
    timeout) printf '%s\n' 'timed out' ;;
    baseline_red) printf '%s\n' 'baseline red' ;;
    inconclusive) printf '%s\n' 'inconclusive' ;;
    no_measurement) printf '%s\n' 'no measurement' ;;
    invalid_evidence) printf '%s\n' 'invalid evidence' ;;
    no_comparable_evidence) printf '%s\n' 'no comparable evidence' ;;
    not_run) printf '%s\n' 'not run (setup failed)' ;;
    skipped) printf '%s\n' 'skipped' ;;
    *) printf '%s\n' 'unknown' ;;
  esac
}

scope_suffix_for_command() {
  local command="$1"
  local scope_flags
  scope_flags="$(scope_flags_for "${command}")"

  if [ -n "${scope_flags}" ]; then
    printf ' %s\n' "${scope_flags}"
  else
    printf '\n'
  fi
}

append_json_hints() {
  local json_file="$1"
  local hints

  hints="$(jq -r '(.data.hints // .hints // [])[]?' "${json_file}" 2>/dev/null || true)"
  [ -n "${hints}" ] || return 0

  while IFS= read -r hint; do
    [ -n "${hint}" ] || continue
    SECTION_BODY+="> :information_source: ${hint}"$'\n'
  done <<< "${hints}"
}

append_lint_details() {
  local json_file="$1"
  local details total

  details="$(jq -r '
    (.data.lint_findings // .lint_findings // [])
    | group_by(.category // "lint")
    | sort_by(-length)
    | .[:10][]?
    | "- `" + (.[0].category // "lint") + "` — " + (length|tostring) + " finding(s)"
  ' "${json_file}" 2>/dev/null || true)"
  total="$(jq -r '(.data.lint_findings // .lint_findings // []) | length' "${json_file}" 2>/dev/null || printf '0')"

  if [ -n "${details}" ]; then
    SECTION_BODY+="${details}"$'\n'
  fi
  if [ "${total}" != "0" ] && [ "${total}" != "null" ]; then
    SECTION_BODY+="- _Total: ${total} finding(s)_"$'\n'
  fi
}

append_audit_details() {
  local json_file="$1"
  local details total

  details="$(jq -r '
    (.data.baseline_comparison.new_items // .data.findings // .findings // [])
    | group_by(.context_label // .convention // .kind // "audit")
    | sort_by(-length)
    | .[:10][]?
    | "- **" + (.[0].context_label // .[0].convention // .[0].kind // "audit") + "** — " + (length|tostring) + " finding(s)"
  ' "${json_file}" 2>/dev/null || true)"
  total="$(jq -r '
    if (.data.baseline_comparison.new_items? // null) != null then .data.baseline_comparison.new_items | length
    elif (.data.findings? // null) != null then .data.findings | length
    elif (.findings? // null) != null then .findings | length
    else 0 end
  ' "${json_file}" 2>/dev/null || printf '0')"

  if [ -n "${details}" ]; then
    SECTION_BODY+="${details}"$'\n'
  fi
  if [ "${total}" != "0" ] && [ "${total}" != "null" ]; then
    SECTION_BODY+="- _Total: ${total} finding(s)_"$'\n'
  fi
}

append_test_details() {
  local json_file="$1"
  local details counts failed passed skipped total

  failed="$(jq -r '(.data.test_counts.failed // .test_counts.failed // 0)' "${json_file}" 2>/dev/null || printf '0')"
  passed="$(jq -r '(.data.test_counts.passed // .test_counts.passed // 0)' "${json_file}" 2>/dev/null || printf '0')"
  skipped="$(jq -r '(.data.test_counts.skipped // .test_counts.skipped // 0)' "${json_file}" 2>/dev/null || printf '0')"
  total="$(jq -r '(.data.test_counts.total // .test_counts.total // 0)' "${json_file}" 2>/dev/null || printf '0')"

  if [ "${total}" != "0" ] && [ "${total}" != "null" ]; then
    if [ "${failed}" != "0" ] && [ "${failed}" != "null" ]; then
      SECTION_BODY+="- **${failed} failed** out of ${total} total"$'\n'
    elif [ "${passed}" != "0" ] && [ "${passed}" != "null" ]; then
      SECTION_BODY+="- ${passed} passed"$'\n'
    fi
    if [ "${skipped}" != "0" ] && [ "${skipped}" != "null" ]; then
      SECTION_BODY+="- ${skipped} skipped"$'\n'
    fi
  fi

  details="$(jq -r '(.data.failed_tests // .failed_tests // [])[:10][]? | "- `" + (.name // .test // .case // "test") + "`"' "${json_file}" 2>/dev/null || true)"
  if [ -n "${details}" ]; then
    SECTION_BODY+="${details}"$'\n'
  fi
}

append_command_details() {
  local command="$1"
  local json_file="$2"

  case "$(quality_base_command "${command}")" in
    audit) append_audit_details "${json_file}" ;;
    lint) append_lint_details "${json_file}" ;;
    test) append_test_details "${json_file}" ;;
  esac
}

append_split_command_section() {
  local command="$1"
  local status json_file scope_suffix icon label

  status="$(command_status "${command}")"
  icon="$(status_icon "${status}")"
  label="$(status_label "${status}")"
  json_file="$(summary_json_for_command "${command}")"
  scope_suffix="$(scope_suffix_for_command "${command}")"

  SECTION_BODY+="${icon} **${command}** — ${label}"$'\n'

  if [ "${status}" = "not_run" ]; then
    SECTION_BODY+="> Setup did not complete, so this command was not executed."$'\n\n'
    return 0
  elif [ -n "${json_file}" ] && [ -f "${json_file}" ]; then
    append_command_details "${command}" "${json_file}"
    append_json_hints "${json_file}"
  else
    SECTION_BODY+="> :warning: Structured output for \`${command}\` was not found. Check the action logs for details."$'\n'
  fi

  if [ "${status}" = "timeout" ]; then
    append_timeout_triage "${command}" "${json_file}"
  fi

  case "${command}" in
    audit|lint|test|build|audit-baseline)
      SECTION_BODY+="> Deep dive: homeboy review ${command} ${COMP_ID}${scope_suffix}"$'\n\n'
      ;;
    *)
      SECTION_BODY+="> Deep dive: homeboy ${command} ${COMP_ID}${scope_suffix}"$'\n\n'
      ;;
  esac
}

append_split_command_sections() {
  local selected command

  IFS=',' read -ra selected <<< "${COMMANDS:-}"
  for command in "${selected[@]}"; do
    command="$(echo "${command}" | xargs)"
    [ -n "${command}" ] || continue
    append_split_command_section "${command}"
  done
}

append_observation_artifact_guidance() {
  local ci_artifact="${HOMEBOY_CI_RESULTS_ARTIFACT:-}"
  local observation_artifact="${HOMEBOY_OBSERVATIONS_ARTIFACT:-}"
  local run_url="${GITHUB_RUN_URL:-}"

  [ -n "${ci_artifact}${observation_artifact}" ] || return 0

  SECTION_BODY+="<details><summary>Artifacts and drill-down</summary>"$'\n\n'

  if [ -n "${ci_artifact}" ]; then
    SECTION_BODY+="- CI results artifact: \`${ci_artifact}\` contains immediate command JSON for this action invocation."$'\n'
  fi

  if [ -n "${observation_artifact}" ]; then
    SECTION_BODY+="- Observation artifact: \`${observation_artifact}\` contains exported Homeboy run history for deeper queries."$'\n'
    SECTION_BODY+="- Drill-down: download the observation artifact, then run \`homeboy runs import <dir>\`, \`homeboy runs list\`, and \`homeboy runs findings <run-id>\`."$'\n'
  fi

  if [ -n "${run_url}" ]; then
    SECTION_BODY+="- Artifacts are attached to the workflow run: ${run_url}"$'\n'
  fi

  SECTION_BODY+=$'\n</details>\n\n'
}

build_section_body() {
  SECTION_BODY="### ${SECTION_TITLE}"$'\n\n'
  local setup_file="${HOMEBOY_SETUP_RESULT_FILE:-${OUTPUT_DIR:+${OUTPUT_DIR}/setup.json}}"
  if [ -n "${setup_file}" ] && [ -f "${setup_file}" ] && jq -e '.schema == "homeboy/action-setup-result/v1" and (.status == "failed" or .status == "timeout")' "${setup_file}" >/dev/null 2>&1; then
    SECTION_BODY+="$(jq -r '"`:x:` **Setup failed - " + .owner + "**\n\n- Step: `" + .step + "`\n- Diagnostic: " + (if .diagnostic == "" then "Inspect the retained setup log." else .diagnostic end) + "\n- Replay: `" + .replay_command + "`\n"' "${setup_file}")"$'\n'
  fi
  append_review_report_section
  append_observation_artifact_guidance
}

# Build the shared tooling footer written at the bottom of every Homeboy Results
# comment. The footer is handed to Homeboy core with `git pr comment
# --footer-file`, so section merging, idempotency, and footer placement stay in
# the core PR-comment surface.
#
# Writes to stdout so the caller can redirect to a tmp file.
build_tooling_footer() {
  local cli_version="${HOMEBOY_CLI_VERSION:-unknown}"
  local extension_id="${HOMEBOY_EXTENSION_ID:-auto}"
  local extension_source="${HOMEBOY_EXTENSION_SOURCE:-auto}"
  local extension_revision="${HOMEBOY_EXTENSION_REVISION:-unknown}"
  local action_repository="${HOMEBOY_ACTION_REPOSITORY:-unknown}"
  local action_ref="${HOMEBOY_ACTION_REF:-unknown}"

  printf '<details><summary>Tooling versions</summary>\n\n'
  printf '%s\n' "- Homeboy CLI: \`${cli_version}\`"
  printf '%s\n' "- Extension: \`${extension_id}\` from \`${extension_source}\`"
  printf '%s\n' "- Extension revision: \`${extension_revision}\`"
  printf '%s\n' "- Action: \`${action_repository}@${action_ref}\`"
  printf '\n</details>\n\n'
}
