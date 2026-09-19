#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="${ROOT_DIR}/scripts/core/run-with-liveness-timeout.sh"
ACTION="${ROOT_DIR}/action.yml"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

success_log="${TMP_DIR}/success.log"
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=2 bash "${RUNNER}" --log-file "${success_log}" "fast command" bash -c 'printf success'
if [ "$(<"${success_log}")" != "success" ]; then
  printf 'FAIL: successful command log was not retained\n'
  exit 1
fi
printf 'PASS: successful command preserves its retained output\n'

set +e
child_pid_file="${TMP_DIR}/child.pid"
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" --log-file "${TMP_DIR}/timeout-command.log" "stalled autofix" bash -c 'sleep 30 & echo $! > "$0"; wait' "${child_pid_file}" >"${TMP_DIR}/timeout.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 124 ]; then
  printf 'FAIL: timeout exits 124, got %s\n' "${exit_code}"
  exit 1
fi

if ! grep -q 'stalled autofix exceeded its 1s execution timeout' "${TMP_DIR}/timeout.log"; then
  printf 'FAIL: timeout reports actionable evidence\n'
  exit 1
fi
printf 'PASS: timeout returns actionable evidence\n'

child_pid="$(<"${child_pid_file}")"
if kill -0 "${child_pid}" 2>/dev/null; then
  printf 'FAIL: timeout leaves descendant process %s alive\n' "${child_pid}"
  exit 1
fi
printf 'PASS: timeout terminates descendant process group\n'

term_ignoring_parent_pid_file="${TMP_DIR}/term-ignoring-parent.pid"
term_ignoring_child_pid_file="${TMP_DIR}/term-ignoring-child.pid"
term_ignoring_start="$(date +%s)"
set +e
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" --log-file "${TMP_DIR}/term-ignoring-command.log" "TERM-ignoring command" bash -c 'trap "" TERM; echo $$ > "$0"; (trap "" TERM; while :; do sleep 1; done) & echo $! > "$1"; printf retained; while :; do sleep 1; done' "${term_ignoring_parent_pid_file}" "${term_ignoring_child_pid_file}" >"${TMP_DIR}/term-ignoring.log" 2>&1
exit_code=$?
set -e
term_ignoring_elapsed="$(( $(date +%s) - term_ignoring_start ))"

if [ "${exit_code}" -ne 124 ]; then
  printf 'FAIL: TERM-ignoring timeout exits 124, got %s\n' "${exit_code}"
  exit 1
fi
if [ "${term_ignoring_elapsed}" -gt 5 ]; then
  printf 'FAIL: TERM-ignoring command exceeded hard wall-clock bound (%ss)\n' "${term_ignoring_elapsed}"
  exit 1
fi
term_ignoring_parent_pid="$(<"${term_ignoring_parent_pid_file}")"
term_ignoring_child_pid="$(<"${term_ignoring_child_pid_file}")"
for pid in "${term_ignoring_parent_pid}" "${term_ignoring_child_pid}"; do
  if kill -0 "${pid}" 2>/dev/null; then
    printf 'FAIL: TERM-ignoring timeout leaves process %s alive\n' "${pid}"
    exit 1
  fi
done
if ! grep -q 'containment grace expired; sending SIGKILL' "${TMP_DIR}/term-ignoring.log"; then
  printf 'FAIL: TERM-ignoring timeout did not report SIGKILL escalation\n'
  exit 1
fi
if [ "$(<"${TMP_DIR}/term-ignoring-command.log")" != "retained" ]; then
  printf 'FAIL: TERM-ignoring cleanup did not preserve command output\n'
  exit 1
fi
printf 'PASS: TERM-ignoring process group is hard-killed and reaped within the timeout bound\n'

leaked_pid_file="${TMP_DIR}/leaked.pid"
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=5 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" --log-file "${TMP_DIR}/leaked-command.log" "leaked child" bash -c 'sleep 30 & echo $! > "$0"; printf retained' "${leaked_pid_file}" >"${TMP_DIR}/cleanup.log" 2>&1
leaked_pid="$(<"${leaked_pid_file}")"
if kill -0 "${leaked_pid}" 2>/dev/null; then
  printf 'FAIL: finalization leaves child process %s alive\n' "${leaked_pid}"
  exit 1
fi
if ! grep -q 'finalization terminated surviving command containment' "${TMP_DIR}/cleanup.log"; then
  printf 'FAIL: finalization does not report child-process cleanup\n'
  exit 1
fi
if [ "$(<"${TMP_DIR}/leaked-command.log")" != "retained" ]; then
  printf 'FAIL: child cleanup did not preserve command output\n'
  exit 1
fi
printf 'PASS: finalization cleans children and reports retained output\n'

# The Linux backend's strict containment story only exists on Linux. The portable
# backend that serves every other platform is covered by
# `test-portable-containment.sh`, which is the suite the macOS self-test job runs.
if [ "$(uname -s)" = Linux ]; then
  strict_pid_file="${TMP_DIR}/strict-double-fork.pid"
  set +e
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_CGROUP_ROOT="${TMP_DIR}/missing-cgroup-root" HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" "strict double-fork" bash -c 'python3 -c "import os, signal, sys, time; os.fork() and sys.exit(0); os.setsid(); os.fork() and sys.exit(0); signal.signal(signal.SIGTERM, signal.SIG_IGN); open(sys.argv[1], \"w\").write(str(os.getpid())); time.sleep(30)" "$0"' "${strict_pid_file}" >"${TMP_DIR}/strict.log" 2>&1
  exit_code=$?
  set -e
  strict_pid="$(<"${strict_pid_file}")"
  if [ "${exit_code}" -ne 124 ] || kill -0 "${strict_pid}" 2>/dev/null; then
    printf 'FAIL: cgroup-denied Linux supervisor did not contain immediate double-fork escape\n'
    exit 1
  fi
  printf 'PASS: cgroup-denied Linux supervisor contains immediate double-fork escape\n'
fi

# Containment failure must not swallow ordinary outcomes: a strict command that
# leaves nothing behind still reports its own exit code, success or failure.
if [ "$(uname -s)" = Linux ]; then
  set +e
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=30 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" "strict clean" bash -c 'exit 0' >"${TMP_DIR}/strict-clean.log" 2>&1
  clean_exit=$?
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=30 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" "strict failing" bash -c 'exit 3' >"${TMP_DIR}/strict-failing.log" 2>&1
  failing_exit=$?
  set -e
  if [ "${clean_exit}" -ne 0 ]; then
    printf 'FAIL: strict clean command reported %s instead of success\n' "${clean_exit}"
    cat "${TMP_DIR}/strict-clean.log"
    exit 1
  fi
  if [ "${failing_exit}" -ne 3 ]; then
    printf 'FAIL: strict failing command reported %s instead of its own exit code\n' "${failing_exit}"
    cat "${TMP_DIR}/strict-failing.log"
    exit 1
  fi
  printf 'PASS: strict commands without leaked descendants report their own exit codes\n'
fi

# A JVM-style launcher can exit just before its already-stopping worker. The
# subreaper must reap that child during finalization rather than killing it and
# rewriting retained structured success as a timeout.
if [ "$(uname -s)" = Linux ]; then
  short_lived_output='{"success":true,"data":{"exit_code":0,"termination":"completed"}}'
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=5 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" --log-file "${TMP_DIR}/short-lived-command.log" "short-lived JVM child" python3 -c 'import os, sys, time; os.write(1, sys.argv[1].encode()); pid = os.fork(); os._exit(0) if pid else (time.sleep(0.2), os._exit(0))' "${short_lived_output}" >"${TMP_DIR}/short-lived.log" 2>&1
  if [ "$(<"${TMP_DIR}/short-lived-command.log")" != "${short_lived_output}" ]; then
    printf 'FAIL: natural descendant drain discarded structured command output\n'
    exit 1
  fi
  if grep -q 'leaked descendants' "${TMP_DIR}/short-lived.log"; then
    printf 'FAIL: naturally exiting JVM-style child was reported as leaked\n'
    exit 1
  fi
  printf 'PASS: Linux supervisor reaps a naturally exiting JVM-style child and preserves structured success\n'

  live_pid_file="${TMP_DIR}/strict-live.pid"
  set +e
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=5 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${RUNNER}" --log-file "${TMP_DIR}/strict-live-command.log" "genuine live child" python3 -c 'import os, sys, time; pid = os.fork(); os._exit(0) if pid else (open(sys.argv[1], "w").write(str(os.getpid())), time.sleep(30), os._exit(0))' "${live_pid_file}" >"${TMP_DIR}/strict-live.log" 2>&1
  live_exit=$?
  set -e
  live_pid="$(<"${live_pid_file}")"
  if [ "${live_exit}" -ne 124 ] || kill -0 "${live_pid}" 2>/dev/null; then
    printf 'FAIL: strict supervisor did not fail and contain a genuinely live child\n'
    exit 1
  fi
  for detail in "pid=${live_pid}" 'state=' 'command=' 'ancestry='; do
    if ! grep -q "${detail}" "${TMP_DIR}/strict-live.log"; then
      printf 'FAIL: live-child diagnostic omitted %s\n' "${detail}"
      exit 1
    fi
  done
  printf 'PASS: genuine live descendants remain failures with actionable process diagnostics\n'
fi

if [ -d /proc ]; then
python3 - "${ROOT_DIR}/scripts/core/run-with-liveness-timeout-supervisor.py" <<'PY'
import importlib.util
import os
import signal
import sys

spec = importlib.util.spec_from_file_location("supervisor", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

original_send = getattr(module.signal, "pidfd_send_signal", None)
module.signal.pidfd_send_signal = lambda pidfd, sig: (_ for _ in ()).throw(ProcessLookupError())
assert module.signal_pidfd(11, signal.SIGTERM)

calls = []
module.identity = lambda pid: 8
module.signal.pidfd_send_signal = lambda pidfd, sig: calls.append((pidfd, sig))
assert module.known_live({(101, 7): 11}) is False
assert calls == []

module.signal.pidfd_send_signal = lambda pidfd, sig: (_ for _ in ()).throw(PermissionError())
assert not module.signal_pidfd(11, signal.SIGTERM)
if original_send is None:
    del module.signal.pidfd_send_signal
else:
    module.signal.pidfd_send_signal = original_send

# A process that exits mid-scan makes /proc/<pid>/stat raise ESRCH, not ENOENT.
# Letting that escape killed the supervisor and orphaned the descendants it
# exists to contain, so descendants() must skip the racing entry and still
# report the ones it can read.
real_open = open


def racing_open(path, *args, **kwargs):
    if path == f"/proc/{os.getpid()}/stat":
        raise ProcessLookupError()
    return real_open(path, *args, **kwargs)


module.open = racing_open
observed = module.descendants(1)
del module.open
assert isinstance(observed, set)
assert os.getpid() not in observed
PY
printf 'PASS: supervisor treats pidfd ESRCH as exited, avoids PID reuse, and fails permission denial\n'
printf 'PASS: supervisor scan survives processes exiting mid-scan\n'
else
  printf 'PASS: Linux /proc supervisor probes are exercised on Linux runners\n'
fi

if [ "$(uname -s)" = Linux ]; then
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true HOMEBOY_ACTION_CGROUP_ROOT="${TMP_DIR}/denied-cgroup" HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=5 bash "${RUNNER}" "rapid child exits" bash -c 'for _ in $(seq 1 200); do (exit 0) & done; wait' >"${TMP_DIR}/rapid.log" 2>&1
  printf 'PASS: Linux supervisor reaps rapid child exits without signal races\n'
fi

set +e
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=invalid bash "${RUNNER}" "invalid timeout" bash -c 'exit 0' >"${TMP_DIR}/invalid.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 2 ]; then
  printf 'FAIL: invalid timeout exits 2, got %s\n' "${exit_code}"
  exit 1
fi
printf 'PASS: invalid timeout is rejected\n'

if ! grep -q '^  execution-timeout-seconds:' "${ACTION}"; then
  printf 'FAIL: action does not expose execution timeout input\n'
  exit 1
fi
if ! grep -q '^  cleanup-timeout-seconds:' "${ACTION}"; then
  printf 'FAIL: action does not expose cleanup timeout input\n'
  exit 1
fi

wrapper_count="$(grep -c 'run-with-liveness-timeout.sh' "${ACTION}" || true)"
if [ "${wrapper_count}" -ne 0 ]; then
  printf 'FAIL: action must leave command timeout enforcement to the per-command runner, got %s action wrappers\n' "${wrapper_count}"
  exit 1
fi
if ! grep -q 'run-with-liveness-timeout.sh' "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh"; then
  printf 'FAIL: quality commands are not individually bounded\n'
  exit 1
fi
if ! grep -q 'run-with-liveness-timeout.sh' "${ROOT_DIR}/scripts/core/run-baseline-commands.sh"; then
  printf 'FAIL: baseline commands are not individually bounded\n'
  exit 1
fi
if grep -q 'run-with-liveness-timeout.sh.*| tee' "${ROOT_DIR}/scripts/core/run-baseline-commands.sh"; then
  printf 'FAIL: baseline command finalization still depends on an unbounded tee pipeline\n'
  exit 1
fi
# Window is sized to span the step's whole `env:` block, not a fixed offset: the
# assertion is that the timeout reaches the baseline step, not where in the block
# it sits.
if ! grep -A26 'name: Run baseline Homeboy commands' "${ACTION}" | grep -q 'HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS:'; then
  printf 'FAIL: baseline commands do not receive the execution timeout input\n'
  exit 1
fi
if ! grep -A26 'name: Run baseline Homeboy commands' "${ACTION}" | grep -q 'HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS:'; then
  printf 'FAIL: baseline commands do not receive the cleanup timeout input\n'
  exit 1
fi
if grep -q 'run-with-liveness-timeout.sh.*| tee' "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh"; then
  printf 'FAIL: quality command finalization still depends on an unbounded tee pipeline\n'
  exit 1
fi
printf 'PASS: action applies bounded execution to each quality and baseline command\n'
