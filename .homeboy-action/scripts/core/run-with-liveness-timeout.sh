#!/usr/bin/env bash

set -euo pipefail

log_file=""
if [ "${1:-}" = "--log-file" ]; then
  log_file="${2:-}"
  shift 2
fi

label="$1"
shift
timeout_seconds="${HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS:-1800}"
cleanup_timeout_seconds="${HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS:-15}"
require_containment_proof="${HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF:-false}"
cgroup_root="${HOMEBOY_ACTION_CGROUP_ROOT:-/sys/fs/cgroup}"

for value_name in timeout_seconds cleanup_timeout_seconds; do
  value="${!value_name}"
  if ! [[ "${value}" =~ ^[1-9][0-9]*$ ]]; then
    echo "::error::${label} ${value_name//_/ } must be a positive number of seconds; received '${value}'."
    exit 2
  fi
done

if [ "${require_containment_proof}" = true ]; then
  supervisor="$(dirname "${BASH_SOURCE[0]}")/run-with-liveness-timeout-supervisor.py"
  if [ -n "${log_file}" ]; then
    exec python3 "${supervisor}" --log-file "${log_file}" "${label}" "${timeout_seconds}" "${cleanup_timeout_seconds}" "$@"
  fi
  exec python3 "${supervisor}" "${label}" "${timeout_seconds}" "${cleanup_timeout_seconds}" "$@"
fi

start_seconds="$(date +%s)"
timeout_marker="$(mktemp)"
containment_failure_marker="$(mktemp)"
descendants_file="$(mktemp)"
rm -f "${timeout_marker}"
rm -f "${containment_failure_marker}" "${descendants_file}"
containment_cgroup=""
owner_pid="${BASHPID}"
cleanup_files() {
  [ "${BASHPID}" = "${owner_pid}" ] || return 0
  rm -f "${timeout_marker}" "${containment_failure_marker}" "${descendants_file}"
  [ -z "${containment_cgroup}" ] || rmdir "${containment_cgroup}" 2>/dev/null || true
}
trap cleanup_files EXIT

pid_is_live() {
  local pid="$1" state
  kill -0 "${pid}" 2>/dev/null || return 1
  state="$(ps -o stat= -p "${pid}" 2>/dev/null | tr -d '[:space:]')"
  [ -n "${state}" ] && [[ "${state}" != Z* ]]
}

record_descendants() {
  local parent="$1" child process_table
  process_table="$(ps -axo pid=,ppid= 2>/dev/null || true)"
  while IFS= read -r child; do
    [ -n "${child}" ] || continue
    printf '%s\n' "${child}" >> "${descendants_file}"
    record_descendants "${child}"
  done < <(printf '%s\n' "${process_table}" | awk -v parent="${parent}" '$2 == parent { print $1 }')
}

tracked_descendants_live() {
  local pid
  [ -f "${descendants_file}" ] || return 1
  while IFS= read -r pid; do
    pid_is_live "${pid}" && return 0
  done < <(sort -u "${descendants_file}")
  return 1
}

signal_tracked_descendants() {
  local signal="$1" pid
  [ -f "${descendants_file}" ] || return 0
  while IFS= read -r pid; do
    pid_is_live "${pid}" && kill "-${signal}" "${pid}" 2>/dev/null || true
  done < <(sort -u "${descendants_file}")
}

containment_is_live() {
  if [ -n "${containment_cgroup}" ]; then
    grep -q '^populated 1$' "${containment_cgroup}/cgroup.events" 2>/dev/null && return 0
  fi
  kill -0 -- "-${command_pid}" 2>/dev/null || tracked_descendants_live
}

terminate_containment() {
  local reason="$1" cleanup_deadline
  local tracked_count=0
  [ -f "${descendants_file}" ] && tracked_count="$(sort -u "${descendants_file}" | wc -l | tr -d '[:space:]')"
  echo "::notice::${label} containment tracker recorded ${tracked_count} descendant PID(s); proof required=${require_containment_proof}."
  echo "::warning::${label} ${reason}; terminating process group ${command_pid} and tracked descendants."
  kill -TERM -- "-${command_pid}" 2>/dev/null || true
  signal_tracked_descendants TERM
  cleanup_deadline="$(( $(date +%s) + cleanup_timeout_seconds ))"
  while containment_is_live && [ "$(date +%s)" -lt "${cleanup_deadline}" ]; do
    sleep 1
  done
  if containment_is_live; then
    echo "::warning::${label} containment grace expired; sending SIGKILL to process group ${command_pid} and tracked descendants."
    kill -KILL -- "-${command_pid}" 2>/dev/null || true
    signal_tracked_descendants KILL
    if [ -n "${containment_cgroup}" ]; then
      echo 1 > "${containment_cgroup}/cgroup.kill" 2>/dev/null || true
    fi
    cleanup_deadline="$(( $(date +%s) + cleanup_timeout_seconds ))"
    while containment_is_live && [ "$(date +%s)" -lt "${cleanup_deadline}" ]; do
      sleep 1
    done
  fi
  if containment_is_live; then
    echo "::error::${label} containment could not prove cleanup within ${cleanup_timeout_seconds}s; surviving descendants escaped the command process group. Retained command output: ${log_file:-standard output}."
    touch "${containment_failure_marker}"
    return 1
  fi
  if [ "${require_containment_proof}" = true ] && [ -z "${containment_cgroup}" ] && [ "${tracked_count}" -gt 0 ]; then
    echo "::error::${label} cleanup cannot prove containment without a dedicated cgroup; tracked descendants may have escaped the command process group. Retained command output: ${log_file:-standard output}."
    touch "${containment_failure_marker}"
    return 1
  fi
  return 0
}

# Establish strict containment before command launch. Moving an already-running
# process into a cgroup leaves a window for a double-forked child to escape.
if [ -d "${cgroup_root}" ] && [ -w "${cgroup_root}" ]; then
  candidate_cgroup="${cgroup_root}/homeboy-action-$$-${RANDOM}"
  if mkdir "${candidate_cgroup}" 2>/dev/null; then
    containment_cgroup="${candidate_cgroup}"
  fi
fi
if [ "${require_containment_proof}" = true ] && [ -z "${containment_cgroup}" ]; then
  echo "::error::${label} cannot establish pre-launch descendant containment; refusing to run this strict command."
  exit 125
fi

# Job control gives the child command its own process group. Capturing its output
# directly in a file prevents a descendant-held stdout pipe from wedging `tee`
# after the command parent exits.
set -m
if [ -n "${containment_cgroup}" ]; then
  if [ -n "${log_file}" ]; then
    HOMEBOY_ACTION_CHILD_CGROUP="${containment_cgroup}" bash -c 'echo "$$" > "${HOMEBOY_ACTION_CHILD_CGROUP}/cgroup.procs" || exit 125; exec "$0" "$@"' "$@" >"${log_file}" 2>&1 &
  else
    HOMEBOY_ACTION_CHILD_CGROUP="${containment_cgroup}" bash -c 'echo "$$" > "${HOMEBOY_ACTION_CHILD_CGROUP}/cgroup.procs" || exit 125; exec "$0" "$@"' "$@" &
  fi
elif [ -n "${log_file}" ]; then
  "$@" >"${log_file}" 2>&1 &
else
  "$@" &
fi
command_pid=$!
set +m
if [ -n "${containment_cgroup}" ]; then
  echo "::notice::${label} is contained in cgroup ${containment_cgroup}."
fi

(
  while pid_is_live "${command_pid}"; do
    record_descendants "${command_pid}"
    sleep 0.1
  done
  record_descendants "${command_pid}"
) &
tracker_pid=$!

(
  while pid_is_live "${command_pid}"; do
    sleep 1
    if pid_is_live "${command_pid}"; then
      elapsed="$(( $(date +%s) - start_seconds ))"
      if [ "${elapsed}" -ge "${timeout_seconds}" ]; then
        echo "::error::${label} exceeded its ${timeout_seconds}s execution timeout."
        touch "${timeout_marker}"
        terminate_containment "timed out" || true
        exit 0
      fi
      if [ "$(( elapsed % 60 ))" -eq 0 ]; then
        echo "::notice::${label} is still running after ${elapsed}s (timeout: ${timeout_seconds}s)."
      fi
    fi
  done
) &
liveness_pid=$!

set +e
wait "${command_pid}"
command_exit=$?
set -e
kill "${liveness_pid}" 2>/dev/null || true
wait "${liveness_pid}" 2>/dev/null || true
wait "${tracker_pid}" 2>/dev/null || true

if [ -f "${timeout_marker}" ]; then
  if [ "${require_containment_proof}" = true ] && [ -z "${containment_cgroup}" ] && [ -s "${descendants_file}" ]; then
    touch "${containment_failure_marker}"
  fi
  if [ -f "${containment_failure_marker}" ]; then
    echo "::error::${label} timed out and cleanup could not prove descendant containment. Retained command output: ${log_file:-standard output}."
    exit 125
  fi
  echo "::error::${label} exceeded its ${timeout_seconds}s execution timeout and was terminated. Inspect this step's logs and rerun after resolving the blocked command; the caller can continue when this action is advisory."
  exit 124
fi

if containment_is_live; then
  terminate_containment "finalization found live command containment" || exit 125
  echo "::notice::${label} finalization terminated surviving command containment; retained command output: ${log_file:-standard output}."
fi

exit "${command_exit}"
