#!/usr/bin/env bash

# Bound setup subprocesses while retaining their output for the owning CI step.
set -euo pipefail

label="${1:?setup step label is required}"
shift
[ "${1:-}" = '--' ] && shift
[ "$#" -gt 0 ] || { printf 'setup step command is required\n' >&2; exit 2; }

timeout_seconds="${HOMEBOY_ACTION_SETUP_TIMEOUT_SECONDS:-900}"
cleanup_seconds="${HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS:-15}"
log_file="$(mktemp)"
trap 'rm -f "${log_file}"' EXIT
results_dir="${HOMEBOY_CI_RESULTS_DIR:-${GITHUB_WORKSPACE:-$(pwd)}/homeboy-ci-results}"
if [ -d "${results_dir}" ] && [ ! -L "${results_dir}" ]; then
  rm -f "${results_dir}/setup.json"
fi

setup_owner() {
  case "${label}" in
    *extension*) printf 'Homeboy extension setup\n' ;;
    *environment*|*dependenc*) printf 'component dependency setup\n' ;;
    *) printf 'Homeboy Action setup\n' ;;
  esac
}

setup_replay_command() {
  local replay='' arg
  for arg in "$@"; do
    printf -v arg '%q' "${arg}"
    replay+="${replay:+ }${arg}"
  done
  printf '%s\n' "${replay}"
}

write_setup_failure() {
  local status="$1" output_dir result_file diagnostic failure_status
  shift
  output_dir="${results_dir}"
  if [ -L "${output_dir}" ] || { [ -e "${output_dir}" ] && [ ! -d "${output_dir}" ]; }; then
    printf '::error::Cannot preserve setup failure because the result path is not a real directory: %s\n' "${output_dir}"
    return 0
  fi

  mkdir -p "${output_dir}"
  result_file="${output_dir}/setup.json"
  diagnostic="$(tail -n 20 "${log_file}" | tail -c 16384 | python3 "$(dirname "${BASH_SOURCE[0]}")/../pr/comment/sanitize-timeout-diagnostic.py" || true)"
  failure_status='failed'
  if [ "${status}" -eq 124 ]; then
    failure_status='timeout'
    diagnostic="${diagnostic:-${label} timed out during action setup after ${timeout_seconds}s}"
  else
    diagnostic="${diagnostic:-${label} failed during action setup with exit code ${status}}"
  fi

  jq -n \
    --arg schema 'homeboy/action-setup-result/v1' \
    --arg status "${failure_status}" \
    --arg owner "$(setup_owner)" \
    --arg step "${label}" \
    --arg replay_command "$(setup_replay_command "$@")" \
    --arg diagnostic "${diagnostic}" \
    --argjson exit_code "${status}" \
    '{schema:$schema,phase:"dependency_build_setup",status:$status,owner:$owner,step:$step,exit_code:$exit_code,replay_command:$replay_command,diagnostic:$diagnostic}' \
    > "${result_file}"

  if [ -n "${GITHUB_ENV:-}" ]; then
    printf 'HOMEBOY_CI_RESULTS_DIR=%s\nHOMEBOY_SETUP_RESULT_FILE=%s\n' "${output_dir}" "${result_file}" >> "${GITHUB_ENV}"
  fi
}

set +e
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS="${timeout_seconds}" \
HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS="${cleanup_seconds}" \
bash "$(dirname "${BASH_SOURCE[0]}")/run-with-liveness-timeout.sh" \
  --log-file "${log_file}" "${label}" "$@"
status=$?
set -e

cat "${log_file}"
if [ "${status}" -ne 0 ]; then
  write_setup_failure "${status}" "$@"
fi
if [ "${status}" -eq 124 ]; then
  echo "::error::${label} timed out during action setup after ${timeout_seconds}s. The retained output above identifies the blocked setup command; retry only after resolving that dependency."
fi
exit "${status}"
