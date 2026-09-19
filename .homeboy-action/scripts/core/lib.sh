#!/usr/bin/env bash

set -euo pipefail

# Source scope module for flag generation
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/../scope/flags.sh"

# Print the canonical lifecycle state for the current PR: open, closed, merged,
# or unknown. GitHub represents merged pull requests as CLOSED plus mergedAt.
pr_lifecycle_state() {
  local pr_number="${1:-${PR_NUMBER:-}}"
  local repo="${GITHUB_REPOSITORY:-}"

  [ -n "${pr_number}" ] && [ -n "${repo}" ] || { printf 'unknown'; return 0; }

  local observation=""
  if command -v gh >/dev/null 2>&1; then
    observation=$(gh pr view "${pr_number}" --repo "${repo}" --json state,mergedAt -q '[.state, .mergedAt] | @tsv' 2>/dev/null || true)
  fi

  if [ -z "${observation}" ]; then
    # Fallback to curl — use GH_TOKEN or GITHUB_TOKEN for auth
    local token="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
    if [ -n "${token}" ]; then
      observation=$(curl -sfL \
        -H "Authorization: Bearer ${token}" \
        -H "Accept: application/vnd.github+json" \
        "https://api.github.com/repos/${repo}/pulls/${pr_number}" 2>/dev/null \
        | jq -r '[.state, .merged_at] | @tsv' 2>/dev/null || true)
    fi
  fi

  local state merged_at
  IFS=$'\t' read -r state merged_at <<< "${observation}"
  case "${state}" in
    OPEN|open) printf 'open' ;;
    MERGED|merged) printf 'merged' ;;
    CLOSED|closed) [ -n "${merged_at:-}" ] && printf 'merged' || printf 'closed' ;;
    *) printf 'unknown' ;;
  esac
}

# Unknown deliberately remains fail-open to avoid cancelling a live PR on a
# transient lookup failure.
pr_is_active() {
  case "$(pr_lifecycle_state "$@")" in
    open|unknown) return 0 ;;
    closed|merged) return 1 ;;
  esac
}

write_multiline_output() {
  local key="$1"
  local value="${2:-}"
  local marker="__HOMEBOY_${key}_$(date +%s%N)__"

  {
    printf '%s<<%s\n' "${key}" "${marker}"
    printf '%s\n' "${value}"
    printf '%s\n' "${marker}"
  } >> "${GITHUB_OUTPUT}"
}

resolve_component_id() {
  if [ -n "${PORTABLE_ID:-}" ]; then
    printf '%s\n' "${PORTABLE_ID}"
  elif [ -n "${COMPONENT_NAME:-}" ]; then
    printf '%s\n' "${COMPONENT_NAME}"
  elif [ -n "${component_id:-}" ]; then
    printf '%s\n' "${component_id}"
  elif [ -f "homeboy.json" ]; then
    local from_portable
    from_portable="$(jq -r '.id // empty' homeboy.json 2>/dev/null || true)"
    if [ -n "${from_portable}" ]; then
      printf '%s\n' "${from_portable}"
    else
      basename "${GITHUB_REPOSITORY}"
    fi
  else
    basename "${GITHUB_REPOSITORY}"
  fi
}

resolve_workspace() {
  # When running in a multi-component repo, COMPONENT_DIR points to the
  # subdirectory containing the component's homeboy.json. Homeboy core
  # uses --path to read config and scope operations to this directory.
  local component_dir="${COMPONENT_DIR:-}"
  if [ -n "${component_dir}" ] && [ "${component_dir}" != "." ]; then
    printf '%s/%s\n' "$(pwd)" "${component_dir}"
  else
    pwd
  fi
}

quality_base_command() {
  local cmd="$1"
  cmd="$(printf '%s' "${cmd}" | xargs)"
  case "${cmd}" in
    "review audit"*) printf '%s\n' "audit" ;;
    "review audit-baseline"*) printf '%s\n' "audit-baseline" ;;
    "review lint"*) printf '%s\n' "lint" ;;
    "review test"*) printf '%s\n' "test" ;;
    "review build"*) printf '%s\n' "build" ;;
    "review ci"*) printf '%s\n' "ci" ;;
    audit|audit\ *) printf '%s\n' "audit" ;;
    audit-baseline|audit-baseline\ *) printf '%s\n' "audit-baseline" ;;
    lint|lint\ *) printf '%s\n' "lint" ;;
    test|test\ *) printf '%s\n' "test" ;;
    build|build\ *) printf '%s\n' "build" ;;
    ci|ci\ *) printf '%s\n' "ci" ;;
    *) printf '%s\n' "$(printf '%s' "${cmd}" | awk '{print $1}')" ;;
  esac
}

# Sort commands into canonical order: audit → lint → test → build → refactor → bench.
# Review-backed audit/lint/test/build are core quality gates; real refactor and bench commands
# run after them when explicitly requested. Release and operations commands are
# handled by dedicated steps and are filtered out here defensively.
canonicalize_commands() {
  local commands="$1"
  local audit="" lint="" test="" build="" refactor="" bench="" others=()
  local cmd base_cmd

  IFS=',' read -ra CMD_ARRAY <<< "${commands}"
  for cmd in "${CMD_ARRAY[@]}"; do
    cmd="$(printf '%s' "${cmd}" | xargs)"
    base_cmd="$(quality_base_command "${cmd}")"
    case "${base_cmd}" in
      audit)   audit="${cmd}" ;;
      lint)    lint="${cmd}" ;;
      test)    test="${cmd}" ;;
      build)   build="${cmd}" ;;
      refactor) refactor="${cmd}" ;;
      bench) bench="${cmd}" ;;
      release|fleet|deploy) ;;
      *)       others+=("${cmd}") ;;
    esac
  done

  local result=()
  [ -n "${audit}" ] && result+=("${audit}")
  [ -n "${lint}" ]  && result+=("${lint}")
  [ -n "${test}" ]  && result+=("${test}")
  [ -n "${build}" ] && result+=("${build}")
  [ -n "${refactor}" ] && result+=("${refactor}")
  [ -n "${bench}" ] && result+=("${bench}")
  result+=("${others[@]+"${others[@]}"}")

  local IFS=','
  printf '%s\n' "${result[*]}"
}

has_lint_command() {
  local commands="$1"
  local cmd
  IFS=',' read -ra CMD_ARRAY <<< "${commands}"

  for cmd in "${CMD_ARRAY[@]}"; do
    if [ "$(quality_base_command "${cmd}")" = "lint" ]; then
      printf '%s\n' "true"
      return 0
    fi
  done

  printf '%s\n' "false"
}

resolve_baseline_commands() {
  local requested_commands="$1"
  local baseline_input="${2:-auto}"
  local source_commands cmd base_cmd requested_cmd requested_base found found_cmd result=()

  baseline_input="$(printf '%s' "${baseline_input}" | xargs)"
  case "${baseline_input}" in
    ""|auto)
      source_commands="${requested_commands}"
      ;;
    none|false|off)
      printf '\n'
      return 0
      ;;
    *)
      source_commands="${baseline_input}"
      ;;
  esac

  IFS=',' read -ra CMD_ARRAY <<< "$(canonicalize_commands "${source_commands}")"
  for cmd in "${CMD_ARRAY[@]}"; do
    cmd="$(echo "${cmd}" | xargs)"
    base_cmd="$(quality_base_command "${cmd}")"
    case "${base_cmd}" in
      audit|lint|test) ;;
      *) continue ;;
    esac

    found=false
    found_cmd=""
    IFS=',' read -ra REQUESTED_ARRAY <<< "${requested_commands}"
    for requested_cmd in "${REQUESTED_ARRAY[@]}"; do
      requested_cmd="$(echo "${requested_cmd}" | xargs)"
      requested_base="$(quality_base_command "${requested_cmd}")"
      if [ "${requested_base}" = "${base_cmd}" ]; then
        found=true
        found_cmd="${requested_cmd}"
        break
      fi
    done

    if [ "${found}" = true ]; then
      result+=("${found_cmd}")
    fi
  done

  local IFS=','
  printf '%s\n' "${result[*]}"
}

# Whether one exact command is selected by the documented baseline input. This
# keeps independently scheduled baseline work aligned with normal reruns.
baseline_command_selected() {
  local command="$1"
  local baseline_input="${2:-auto}"
  local requested

  baseline_input="$(printf '%s' "${baseline_input}" | xargs)"
  case "${baseline_input}" in
    auto)
      case "${command}" in
        review\ audit|review\ lint|review\ test|audit|lint|test) return 0 ;;
      esac
      return 1
      ;;
    none|false|off|'') return 1 ;;
  esac

  IFS=',' read -ra REQUESTED_BASELINES <<< "${baseline_input}"
  for requested in "${REQUESTED_BASELINES[@]}"; do
    [ "$(printf '%s' "${requested}" | xargs)" = "${command}" ] && return 0
  done
  return 1
}

settings_json_flags() {
  local raw="${HOMEBOY_SETTINGS_JSON:-}"
  if [ -z "${raw}" ] || [ "${raw}" = "{}" ]; then
    return 0
  fi

  if ! printf '%s' "${raw}" | jq -e 'type == "object"' >/dev/null 2>&1; then
    echo "::warning::HOMEBOY_SETTINGS_JSON must be a JSON object; ignoring settings override"
    return 0
  fi

  local key
  local value
  local flag_value
  while IFS=$'\t' read -r key value; do
    [ -n "${key}" ] || continue
    flag_value="${key}=${value}"
    printf ' --setting-json %q' "${flag_value}"
  done < <(printf '%s' "${raw}" | jq -r 'to_entries[] | [.key, (.value | tojson)] | @tsv')
}

valid_command_result_output() {
  local output_file="$1" expected_command="$2" command_exit="$3"
  # Homeboy reports the executed top-level command in the result envelope.
  # Legacy quality aliases (`audit`, `lint`, `test`, `build`) are executed
  # through `review`, so validate them against the translated command root.
  local expected_root
  case "$(quality_base_command "${expected_command}")" in
    audit|audit-baseline|lint|test|build) expected_root="review" ;;
    *) expected_root="$(printf '%s' "${expected_command}" | awk '{print $1}')" ;;
  esac
  jq -e \
    --arg command "${expected_root}" \
    --argjson command_exit "${command_exit}" '
      type == "object"
      and .schema == "homeboy/command-result/v3"
      and .command == $command
      and (.success | type == "boolean")
      and (.status | type == "string")
      and (.exit_code | type == "number")
      and .exit_code == $command_exit
      and (if .success
           then .status == "succeeded" and .exit_code == 0
           else .status == "failed" and .exit_code != 0
           end)
    ' "${output_file}" >/dev/null 2>&1
}

# Observations retain terminal errors when a command fails before it can write
# its command-result envelope. Keep diagnostics single-line and redact common
# credential forms before sending them to GitHub logs or summaries.
observation_terminal_diagnostics() {
  local observation_dir="$1"
  local command="$2"
  local command_kind
  command_kind="$(quality_base_command "${command}")"

  [ -d "${observation_dir}" ] || return 0

  find "${observation_dir}" -type f -name runs.json -print0 2>/dev/null \
    | while IFS= read -r -d '' runs_file; do
        jq -r --arg kind "${command_kind}" '
          if type == "array" then . else [] end
          | .[]
          | select(.status == "error" and .kind == $kind)
          | .metadata_json.error?
          | select(type == "string" and length > 0)
          | .[:1000]
          | gsub("(?i)(bearer[[:space:]]+)[^[:space:]]+"; "\\1[REDACTED]")
          | gsub("(?i)((token|password|secret|api[_-]?key)[=:][[:space:]]*)[^[:space:]]+"; "\\1[REDACTED]")
          | gsub("(?i)gh[opsu]_[A-Za-z0-9_]+"; "[REDACTED]")
          | gsub("(?i)github_pat_[A-Za-z0-9_]+"; "[REDACTED]")
          | gsub("[\\r\\n]+"; " ")
        ' "${runs_file}" 2>/dev/null || true
      done \
    | sort -u
}

review_subcommand_run_command() {
  local cmd="$1"
  local component_id="$2"
  local workspace="$3"
  local global_flags="$4"
  local placement_flags review_cmd base_cmd rest

  review_cmd="${cmd#review }"
  base_cmd="$(printf '%s' "${review_cmd}" | awk '{print $1}')"
  rest="$(printf '%s' "${review_cmd#${base_cmd}}" | xargs)"
  placement_flags="$(homeboy_command_placement_flags "review ${base_cmd}")"

  if [ -n "${rest}" ]; then
    printf 'homeboy %sreview %s %s%s %s --path %s\n' "${global_flags}" "${base_cmd}" "${placement_flags}" "${component_id}" "${rest}" "${workspace}"
  else
    printf 'homeboy %sreview %s %s%s --path %s\n' "${global_flags}" "${base_cmd}" "${placement_flags}" "${component_id}" "${workspace}"
  fi
}

homeboy_global_flags() {
  local output_file="${1:-}"
  local flags=""

  if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
    homeboy_placement_mode
    case "${HOMEBOY_ACTION_PLACEMENT_MODE}" in
      global)
      flags="${flags}--placement local "
      ;;
      legacy)
      flags="${flags}--force-hot --allow-local-hot "
      ;;
    esac
  fi

  # --output is a global flag and must appear before the subcommand
  # (clap global args don't propagate when placed after positional args)
  if [ -n "${output_file}" ]; then
    flags="${flags}--output ${output_file} "
  fi

  printf '%s' "${flags}"
}

# The action preflights this after selecting the binary. Keep this fallback for
# direct script consumers and cache the probe within a shell process.
homeboy_help_supports_placement() {
  local output_file command_pid timeout_seconds cleanup_timeout_seconds deadline
  local label="homeboy $* --help placement capability probe"

  timeout_seconds="${HOMEBOY_ACTION_PLACEMENT_PROBE_TIMEOUT_SECONDS:-10}"
  cleanup_timeout_seconds="${HOMEBOY_ACTION_PLACEMENT_PROBE_CLEANUP_TIMEOUT_SECONDS:-5}"
  for value_name in timeout_seconds cleanup_timeout_seconds; do
    if ! [[ "${!value_name}" =~ ^[1-9][0-9]*$ ]]; then
      printf '::warning::%s %s must be a positive number of seconds; using the default.\n' "${label}" "${value_name//_/ }" >&2
      if [ "${value_name}" = "timeout_seconds" ]; then
        timeout_seconds=10
      else
        cleanup_timeout_seconds=5
      fi
    fi
  done

  output_file="$(mktemp)"
  # Job control gives the probe and any child it leaves behind a dedicated
  # process group, so a stalled help command cannot retain this shell's pipe.
  set -m
  homeboy "$@" --help >"${output_file}" 2>&1 &
  command_pid=$!
  set +m

  deadline="$(( $(date +%s) + timeout_seconds ))"
  while kill -0 "${command_pid}" 2>/dev/null; do
    if [ "$(date +%s)" -ge "${deadline}" ]; then
      printf '::warning::%s exceeded its %ss timeout; terminating process group %s.\n' "${label}" "${timeout_seconds}" "${command_pid}" >&2
      kill -TERM -- "-${command_pid}" 2>/dev/null || true
      break
    fi
    sleep 0.1
  done

  if kill -0 -- "-${command_pid}" 2>/dev/null; then
    deadline="$(( $(date +%s) + cleanup_timeout_seconds ))"
    while kill -0 -- "-${command_pid}" 2>/dev/null && [ "$(date +%s)" -lt "${deadline}" ]; do
      sleep 0.1
    done
  fi

  if kill -0 -- "-${command_pid}" 2>/dev/null; then
    printf '::warning::%s did not exit after %ss cleanup; sending SIGKILL to process group %s.\n' "${label}" "${cleanup_timeout_seconds}" "${command_pid}" >&2
    kill -KILL -- "-${command_pid}" 2>/dev/null || true
  fi

  wait "${command_pid}" 2>/dev/null || true

  if kill -0 -- "-${command_pid}" 2>/dev/null; then
    printf '::warning::%s retained process group %s after bounded cleanup.\n' "${label}" "${command_pid}" >&2
    rm -f "${output_file}"
    return 1
  fi

  if grep -E '^[[:space:]]+--placement([[:space:]]|<)' "${output_file}" >/dev/null; then
    rm -f "${output_file}"
    return 0
  fi

  rm -f "${output_file}"
  return 1
}

homeboy_placement_mode() {
  if [ -z "${HOMEBOY_ACTION_PLACEMENT_MODE+x}" ]; then
    if homeboy_help_supports_placement; then
      HOMEBOY_ACTION_PLACEMENT_MODE=global
    elif homeboy_help_supports_placement review audit; then
      HOMEBOY_ACTION_PLACEMENT_MODE=scoped
    else
      HOMEBOY_ACTION_PLACEMENT_MODE=legacy
    fi
  fi

}

homeboy_command_placement_flags() {
  local command="$1"
  local command_parts=()

  homeboy_placement_mode
  if [ "${GITHUB_ACTIONS:-}" = "true" ] && [ "${HOMEBOY_ACTION_PLACEMENT_MODE}" = "scoped" ]; then
    case "${command}" in
      review\ audit|review\ lint|review\ test|review\ build|review)
        read -ra command_parts <<< "${command}"
        if homeboy_help_supports_placement "${command_parts[@]}"; then
          printf '%s' '--placement local '
        fi
        ;;
    esac
  fi
}

build_run_command() {
  local cmd
  cmd="$(printf '%s' "$1" | xargs)"
  local component_id="$2"
  local workspace="$3"
  local output_file="${4:-}"
  local full_cmd
  local global_flags
  global_flags="$(homeboy_global_flags "${output_file}")"

  if [[ "${cmd}" == refactor* ]]; then
    local refactor_args
    refactor_args="$(printf '%s' "${cmd#refactor}" | xargs)"
    if [[ "${refactor_args}" == "lint" || "${refactor_args}" == "lint "* ]]; then
      refactor_args="--from lint${refactor_args#lint}"
    fi
    full_cmd="homeboy ${global_flags}refactor ${component_id} ${refactor_args} --path ${workspace}"
  elif [[ "${cmd}" == bench* ]]; then
    local bench_args
    bench_args="$(printf '%s' "${cmd#bench}" | xargs)"
    full_cmd="homeboy ${global_flags}bench ${component_id}"
    [ -n "${bench_args}" ] && full_cmd="${full_cmd} ${bench_args}"
    full_cmd="${full_cmd} --path ${workspace}"
  elif [[ "${cmd}" == "review ci"* ]]; then
    full_cmd="homeboy ${global_flags}${cmd}"
  elif [[ "${cmd}" == "review "* ]]; then
    full_cmd="$(review_subcommand_run_command "${cmd}" "${component_id}" "${workspace}" "${global_flags}")"
  elif [[ "$(quality_base_command "${cmd}")" =~ ^(audit|audit-baseline|lint|test|build)$ ]]; then
    full_cmd="$(review_subcommand_run_command "review ${cmd}" "${component_id}" "${workspace}" "${global_flags}")"
  else
    full_cmd="homeboy ${global_flags}${cmd} ${component_id} --path ${workspace}"
  fi

  local scope
  scope="$(scope_flags_for "${cmd}")"
  [ -n "${scope}" ] && full_cmd="${full_cmd} ${scope}"

  if [ -n "${EXTRA_ARGS:-}" ]; then
    full_cmd="${full_cmd} ${EXTRA_ARGS}"
  fi

  if [[ "${cmd}" == bench* ]]; then
    if [ -n "${BENCH_RIG:-}" ]; then
      full_cmd="${full_cmd} --rig ${BENCH_RIG}"
    fi
    if [ -n "${BENCH_SCENARIO:-}" ]; then
      full_cmd="${full_cmd} --scenario ${BENCH_SCENARIO}"
    fi
    if [ -n "${BENCH_RUNS:-}" ]; then
      full_cmd="${full_cmd} --runs ${BENCH_RUNS}"
    fi
    if [ -n "${BENCH_ITERATIONS:-}" ]; then
      full_cmd="${full_cmd} --iterations ${BENCH_ITERATIONS}"
    fi
    if [ -n "${BENCH_REGRESSION_THRESHOLD:-}" ]; then
      full_cmd="${full_cmd} --regression-threshold ${BENCH_REGRESSION_THRESHOLD}"
    fi
    full_cmd="${full_cmd}$(settings_json_flags)"
  fi

  printf '%s\n' "${full_cmd}"
}

build_review_report_command() {
  local component_id="$1"
  local workspace="$2"
  local full_cmd
  local global_flags

  global_flags="$(homeboy_global_flags)"

  full_cmd="homeboy ${global_flags}review $(homeboy_command_placement_flags review)${component_id} --path ${workspace} --report=pr-comment"

  local scope
  scope="$(scope_flags_for "review")"
  [ -n "${scope}" ] && full_cmd="${full_cmd} ${scope}"

  printf '%s\n' "${full_cmd}"
}

build_observation_export_command() {
  local since_window="$1"
  local output_dir="$2"

  printf 'homeboy runs export --since %s --output %s\n' "${since_window}" "${output_dir}"
}

build_observation_import_command() {
  local import_dir="$1"

  printf 'homeboy runs import %s\n' "${import_dir}"
}

command_output_stem() {
  local cmd="$1"
  local stem
  stem="$(printf '%s' "${cmd}" | sed -E 's/[^[:alnum:]._-]+/-/g; s/^-+//; s/-+$//')"
  stem="${stem#-}"
  stem="${stem%-}"
  if [ -z "${stem}" ]; then
    stem="homeboy-output"
  fi
  printf '%s\n' "${stem}"
}

command_result_filename() {
  printf '%s.json\n' "$(command_output_stem "$1")"
}
