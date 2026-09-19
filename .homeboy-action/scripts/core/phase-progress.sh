#!/usr/bin/env bash

set -euo pipefail

phase_progress_file() {
  printf '%s\n' "${HOMEBOY_ACTION_PHASE_PROGRESS_FILE:-${RUNNER_TEMP:-/tmp}/homeboy-action-phase-progress-${GITHUB_RUN_ID:-local}-${GITHUB_JOB:-local}.jsonl}"
}

phase_run_ref() {
  if [ -n "${GITHUB_RUN_ID:-}" ] && [ -n "${GITHUB_REPOSITORY:-}" ]; then
    printf 'github://%s/actions/runs/%s#%s\n' "${GITHUB_REPOSITORY}" "${GITHUB_RUN_ID}" "${GITHUB_JOB:-action}"
  else
    printf 'homeboy-action://local/%s\n' "${GITHUB_JOB:-action}"
  fi
}

phase_owner() {
  case "$1" in
    preparation|changed_scope_resolution) printf 'action context setup\n' ;;
    dependency_build_setup) printf 'action dependency/build setup\n' ;;
    command_execution) printf 'Homeboy command runner\n' ;;
    release_planning|release_execution) printf 'Homeboy release subsystem\n' ;;
    finding_reconciliation) printf 'action result reconciliation\n' ;;
    artifact_publication) printf 'action artifact publication\n' ;;
    cleanup) printf 'action cleanup\n' ;;
    *) printf 'Homeboy Action\n' ;;
  esac
}

phase_reproduction_command() {
  if [ -n "${GITHUB_RUN_ID:-}" ] && [ -n "${GITHUB_REPOSITORY:-}" ]; then
    printf 'gh run view %s --repo %s --log\n' "${GITHUB_RUN_ID}" "${GITHUB_REPOSITORY}"
  else
    case "$1" in
      release_planning|release_execution) printf 'bash scripts/release/run-release-with-liveness.sh\n' ;;
      *) printf 'bash scripts/core/run-homeboy-commands.sh\n' ;;
    esac
  fi
}

phase_validate_positive() {
  local name="$1" value="$2"
  if ! [[ "${value}" =~ ^[1-9][0-9]*$ ]]; then
    printf '::error::%s must be a positive integer; received %q.\n' "${name}" "${value}"
    exit 2
  fi
}

phase_append() {
  local phase="$1" event="$2" status="$3" elapsed_seconds="$4"
  local file owner reproduction
  file="$(phase_progress_file)"
  owner="$(phase_owner "${phase}")"
  reproduction="$(phase_reproduction_command "${phase}")"
  mkdir -p "$(dirname "${file}")"
  jq -cn \
    --arg schema 'homeboy/action-phase-progress-event/v1' \
    --arg run_ref "$(phase_run_ref)" \
    --arg phase "${phase}" \
    --arg event "${event}" \
    --arg status "${status}" \
    --arg owner "${owner}" \
    --arg reproduction_command "${reproduction}" \
    --argjson at_seconds "$(date +%s)" \
    --argjson elapsed_seconds "${elapsed_seconds}" \
    '{schema:$schema,run_ref:$run_ref,phase:$phase,event:$event,status:$status,owner:$owner,reproduction_command:$reproduction_command,at_seconds:$at_seconds,elapsed_seconds:$elapsed_seconds}' \
    >> "${file}"
}

phase_last_start() {
  local phase="$1" file="$2"
  [ -f "${file}" ] || return 1
  jq -sr --arg phase "${phase}" '[.[] | select(.phase == $phase and .event == "started") | .at_seconds] | last // empty' "${file}"
}

phase_annotations_verbose() {
  case "${HOMEBOY_ACTION_PHASE_ANNOTATIONS:-${HOMEBOY_ACTION_DEBUG:-${RUNNER_DEBUG:-}}}" in
    1|true|verbose|debug) return 0 ;;
    *) return 1 ;;
  esac
}

phase_first_failure() {
  local phase="$1" file="$2"
  jq -se --arg phase "${phase}" \
    '[.[] | select(.phase == $phase and .event == "completed" and .status == "failed")] | length == 1' \
    "${file}" >/dev/null
}

phase_failure_annotation() {
  local phase="$1" elapsed_seconds="$2" file="$3"
  # The JSONL artifact retains every attempt; CI gets one stable terminal signal.
  phase_first_failure "${phase}" "${file}" || return 0
  printf '::error title=Homeboy phase failed [%s]::%s failed after %ss; owner: %s. Reproduce: %s\n' \
    "${phase}" "${phase}" "${elapsed_seconds}" "$(phase_owner "${phase}")" "$(phase_reproduction_command "${phase}")"
}

phase_start() {
  local phase="$1"
  phase_append "${phase}" started running 0
  if phase_annotations_verbose; then
    printf '::notice title=Homeboy phase::%s started; run %s.\n' "${phase}" "$(phase_run_ref)"
  fi
}

phase_end() {
  local phase="$1" status="${2:-completed}"
  local file started now elapsed
  file="$(phase_progress_file)"
  now="$(date +%s)"
  started="$(phase_last_start "${phase}" "${file}" || true)"
  elapsed=0
  if [[ "${started}" =~ ^[0-9]+$ ]]; then
    elapsed="$(( now - started ))"
  else
    status=skipped
  fi
  phase_append "${phase}" completed "${status}" "${elapsed}"
  if [ "${status}" = failed ]; then
    phase_failure_annotation "${phase}" "${elapsed}" "${file}"
  fi
  if phase_annotations_verbose; then
    printf '::notice title=Homeboy phase::%s %s after %ss; run %s.\n' "${phase}" "${status}" "${elapsed}" "$(phase_run_ref)"
  fi
}

phase_run() {
  local phase="$1"
  shift
  [ "${1:-}" = '--' ] && shift
  [ "$#" -gt 0 ] || { printf 'phase-progress run requires a command\n' >&2; exit 2; }

  local heartbeat_seconds="${HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS:-60}"
  local budget_seconds="${HOMEBOY_ACTION_PHASE_BUDGET_SECONDS:-300}"
  phase_validate_positive HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS "${heartbeat_seconds}"
  phase_validate_positive HOMEBOY_ACTION_PHASE_BUDGET_SECONDS "${budget_seconds}"

  phase_start "${phase}"
  local started pid heartbeat_pid exit_code=0
  started="$(date +%s)"
  "$@" &
  pid=$!
  (
    local elapsed=0 over_budget=false sleep_pid=''
    trap '[ -z "${sleep_pid}" ] || kill "${sleep_pid}" 2>/dev/null || true' EXIT TERM INT
    while kill -0 "${pid}" 2>/dev/null; do
      sleep "${heartbeat_seconds}" &
      sleep_pid=$!
      wait "${sleep_pid}" || exit 0
      sleep_pid=''
      if kill -0 "${pid}" 2>/dev/null; then
        elapsed="$(( $(date +%s) - started ))"
        if phase_annotations_verbose; then
          printf '::notice title=Homeboy phase heartbeat::%s running for %ss; run %s.\n' "${phase}" "${elapsed}" "$(phase_run_ref)"
        else
          printf 'Homeboy phase heartbeat::%s running for %ss; run %s.\n' "${phase}" "${elapsed}" "$(phase_run_ref)"
        fi
        if [ "${elapsed}" -ge "${budget_seconds}" ] && [ "${over_budget}" = false ]; then
          over_budget=true
          if phase_annotations_verbose; then
            printf '::warning title=Homeboy phase budget exceeded::%s exceeded its %ss budget; owner: %s. Reproduce: %s\n' "${phase}" "${budget_seconds}" "$(phase_owner "${phase}")" "$(phase_reproduction_command)"
          else
            printf 'Homeboy phase budget exceeded::%s exceeded its %ss budget; owner: %s. Reproduce: %s\n' "${phase}" "${budget_seconds}" "$(phase_owner "${phase}")" "$(phase_reproduction_command)"
          fi
        fi
      fi
    done
  ) &
  heartbeat_pid=$!
  set +e
  wait "${pid}"
  exit_code=$?
  set -e
  kill "${heartbeat_pid}" 2>/dev/null || true
  wait "${heartbeat_pid}" 2>/dev/null || true
  if [ "${exit_code}" -eq 0 ]; then
    phase_end "${phase}" completed
  else
    phase_end "${phase}" failed
  fi
  return "${exit_code}"
}

phase_summary() {
  local file output_dir output
  file="$(phase_progress_file)"
  output_dir="${HOMEBOY_CI_RESULTS_DIR:-${GITHUB_WORKSPACE:-$(pwd)}/homeboy-ci-results}"
  output="${output_dir}/phase-progress.json"
  mkdir -p "${output_dir}"
  if [ ! -f "${file}" ]; then
    : > "${file}"
  fi
  jq -s --arg run_ref "$(phase_run_ref)" '
    [ .[] | select(.event == "completed") ] as $phases
    | {
        schema: "homeboy/action-phase-progress/v1",
        run_ref: $run_ref,
        phases: $phases,
        slowest_phases: ($phases | sort_by(-.elapsed_seconds, .phase) | .[:3])
      }
  ' "${file}" > "${output}"

  local summary_file="${GITHUB_STEP_SUMMARY:-}"
  if [ -n "${summary_file}" ]; then
    {
      printf '### Homeboy Action Phase Timings\n\n'
      printf 'Run: `%s`\n\n' "$(phase_run_ref)"
      printf '| Phase | Status | Elapsed | Owner |\n| --- | --- | ---: | --- |\n'
      jq -r '.phases[] | "| `\(.phase)` | \(.status) | \(.elapsed_seconds)s | \(.owner) |"' "${output}"
      printf '\nThree slowest phases:\n'
      jq -r '.slowest_phases[] | "- `\(.phase)` - \(.elapsed_seconds)s (\(.owner))"' "${output}"
    } >> "${summary_file}"
  fi
  printf 'phase-progress=%s\n' "${output}" >> "${GITHUB_OUTPUT:-/dev/null}"
  if phase_annotations_verbose; then
    printf '::notice title=Homeboy phase timings::published %s for run %s.\n' "${output}" "$(phase_run_ref)"
  fi
}

case "${1:-}" in
  start) phase_start "${2:?phase required}" ;;
  end) phase_end "${2:?phase required}" "${3:-completed}" ;;
  run) shift; phase_run "$@" ;;
  summary) phase_summary ;;
  *)
    printf 'Usage: %s {start|end|run|summary} ...\n' "$0" >&2
    exit 2
    ;;
esac
