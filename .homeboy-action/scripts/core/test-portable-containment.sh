#!/usr/bin/env bash

# Contract tests for the portable strict-containment backend.
#
# This is the only strict backend available off Linux, so it is what macOS
# consumers of Homeboy Action actually run: `review build` gating an Xcode
# compile has no Linux kernel to borrow a subreaper or a pidfd from. The suite is
# therefore written to run unchanged on both platforms — Bash 3.2 syntax only, no
# GNU-only utilities — and the self-test workflow runs it on a macOS runner as
# well as on Linux, where the backend is selected explicitly so its contract
# stays under test on every pull request.
#
# The properties under test are the ones strict mode exists for:
#   * a clean command reports its own exit code, success or failure
#   * a descendant that stayed inside the command session is terminated
#   * a descendant that escaped the session is not laundered into a pass

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
RUNNER="${ROOT_DIR}/scripts/core/run-with-liveness-timeout.sh"
SUPERVISOR="${ROOT_DIR}/scripts/core/run-with-liveness-timeout-supervisor.py"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

# On Darwin `auto` already resolves here, so exercising `auto` proves the real
# consumer path. On Linux `auto` resolves to the pidfd backend, so the portable
# one has to be requested by name.
if [ "$(uname -s)" = Darwin ]; then
  BACKEND=auto
else
  BACKEND=posix
fi

fail() {
  printf 'FAIL: %s\n' "$1"
  shift
  for log in "$@"; do
    [ -f "${log}" ] && cat "${log}"
  done
  exit 1
}

portable_runner() {
  timeout_seconds="$1"
  cleanup_seconds="$2"
  shift 2
  HOMEBOY_ACTION_CONTAINMENT_BACKEND="${BACKEND}" \
    HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true \
    HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS="${timeout_seconds}" \
    HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS="${cleanup_seconds}" \
    bash "${RUNNER}" "$@"
}

# A detached descendant is written by python so the escape is a real setsid, not
# a shell approximation. Both escape shapes share this body; only the setsid call
# differs, which is exactly the line that decides whether the process group can
# still name it.
detach_command() {
  detach_call="$1"
  pid_file="$2"
  printf 'import os, signal, sys, time\n'
  printf 'os.fork() and sys.exit(0)\n'
  [ -n "${detach_call}" ] && printf '%s\n' "${detach_call}"
  printf 'os.fork() and sys.exit(0)\n'
  printf 'signal.signal(signal.SIGTERM, signal.SIG_IGN)\n'
  printf 'open(%s, "w").write(str(os.getpid()))\n' "'${pid_file}'"
  printf 'time.sleep(30)\n'
}

reap_stray() {
  [ -f "$1" ] || return 0
  stray="$(cat "$1")"
  [ -n "${stray}" ] && kill -9 "${stray}" 2>/dev/null
  return 0
}

clean_command_log="${TMP_DIR}/clean-command.log"
portable_runner 30 2 --log-file "${clean_command_log}" "portable clean" bash -c 'printf success' >"${TMP_DIR}/clean.log" 2>&1
clean_exit=$?
portable_runner 30 2 "portable failing" bash -c 'exit 3' >"${TMP_DIR}/failing.log" 2>&1
failing_exit=$?

if [ "${clean_exit}" -ne 0 ]; then
  fail "portable backend reported ${clean_exit} for a clean command" "${TMP_DIR}/clean.log"
fi
if [ "$(cat "${clean_command_log}")" != "success" ]; then
  fail "portable backend did not retain clean command output" "${TMP_DIR}/clean.log"
fi
if [ "${failing_exit}" -ne 3 ]; then
  fail "portable backend reported ${failing_exit} instead of the command exit code" "${TMP_DIR}/failing.log"
fi
printf 'PASS: portable backend passes command exit codes through unchanged\n'

if ! grep -q 'is contained by session-scoped termination with inherited-descriptor containment proof' "${TMP_DIR}/clean.log"; then
  fail 'portable backend did not announce which containment guarantee it provides' "${TMP_DIR}/clean.log"
fi
printf 'PASS: portable backend names its containment guarantee in the job log\n'

timeout_pid_file="${TMP_DIR}/timeout.pid"
portable_runner 1 2 "portable timeout" bash -c 'sleep 30 & echo $! > "$0"; wait' "${timeout_pid_file}" >"${TMP_DIR}/timeout.log" 2>&1
timeout_exit=$?
if [ "${timeout_exit}" -ne 124 ]; then
  fail "portable backend timeout reported ${timeout_exit} instead of 124" "${TMP_DIR}/timeout.log"
fi
if kill -0 "$(cat "${timeout_pid_file}")" 2>/dev/null; then
  reap_stray "${timeout_pid_file}"
  fail 'portable backend timeout left a descendant alive' "${TMP_DIR}/timeout.log"
fi
printf 'PASS: portable backend terminates the session on timeout and reports 124\n'

leaked_pid_file="${TMP_DIR}/leaked.pid"
leaked_command_log="${TMP_DIR}/leaked-command.log"
portable_runner 30 2 --log-file "${leaked_command_log}" "portable leaked child" bash -c 'sleep 30 & echo $! > "$0"; printf retained' "${leaked_pid_file}" >"${TMP_DIR}/leaked.log" 2>&1
leaked_exit=$?
if kill -0 "$(cat "${leaked_pid_file}")" 2>/dev/null; then
  reap_stray "${leaked_pid_file}"
  fail 'portable backend left a leaked descendant alive after the command exited' "${TMP_DIR}/leaked.log"
fi
# The command exited 0 while leaking a descendant we had to kill. Strict mode
# exists to prove containment, so that is a containment failure, not a pass.
if [ "${leaked_exit}" -ne 124 ]; then
  fail "portable backend laundered a leaked descendant into exit ${leaked_exit}" "${TMP_DIR}/leaked.log"
fi
if [ "$(cat "${leaked_command_log}")" != "retained" ]; then
  fail 'portable backend discarded command output while cleaning a leaked descendant' "${TMP_DIR}/leaked.log"
fi
printf 'PASS: portable backend contains a leaked descendant and refuses to certify its success\n'

# A double fork with no setsid is reparented away from the supervisor, so the
# parent chain no longer names it. Its inherited process group still does, which
# is the descendant shape the portable backend can kill.
reparented_pid_file="${TMP_DIR}/reparented.pid"
reparented_script="${TMP_DIR}/reparented.py"
detach_command "" "${reparented_pid_file}" >"${reparented_script}"
portable_runner 1 2 "portable reparented double-fork" python3 "${reparented_script}" >"${TMP_DIR}/reparented.log" 2>&1
reparented_exit=$?
if [ ! -f "${reparented_pid_file}" ]; then
  fail 'reparented double-fork fixture never reported its PID' "${TMP_DIR}/reparented.log"
fi
if kill -0 "$(cat "${reparented_pid_file}")" 2>/dev/null; then
  reap_stray "${reparented_pid_file}"
  fail 'portable backend left a reparented in-group double-fork alive' "${TMP_DIR}/reparented.log"
fi
if [ "${reparented_exit}" -eq 0 ]; then
  fail 'portable backend certified a command that leaked a reparented double-fork' "${TMP_DIR}/reparented.log"
fi
if ! grep -q 'containment grace expired; sending SIGKILL to process group' "${TMP_DIR}/reparented.log"; then
  fail 'portable backend did not escalate to SIGKILL for a TERM-ignoring descendant' "${TMP_DIR}/reparented.log"
fi
printf 'PASS: portable backend kills a reparented double-fork through the command process group\n'

# A double fork that also calls setsid leaves both the process group and the
# parent chain, so no portable scan of the process table can name it. The
# inherited descriptor is what the backend still has: it never reaches EOF, so
# the command is reported as an unproven containment rather than a pass.
escape_pid_file="${TMP_DIR}/escape.pid"
escape_script="${TMP_DIR}/escape.py"
detach_command "os.setsid()" "${escape_pid_file}" >"${escape_script}"
portable_runner 1 2 "portable detached double-fork" python3 "${escape_script}" >"${TMP_DIR}/escape.log" 2>&1
escape_exit=$?
reap_stray "${escape_pid_file}"
if [ "${escape_exit}" -ne 125 ]; then
  fail "portable backend reported ${escape_exit} for a session escape instead of a containment failure" "${TMP_DIR}/escape.log"
fi
if ! grep -q 'could not prove descendant containment' "${TMP_DIR}/escape.log"; then
  fail 'portable backend containment failure did not name the unproven descendant' "${TMP_DIR}/escape.log"
fi
printf 'PASS: portable backend reports a containment failure for a session escape it cannot signal\n'

HOMEBOY_ACTION_CONTAINMENT_BACKEND=cgroups HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=true bash "${RUNNER}" "unknown backend" bash -c 'exit 0' >"${TMP_DIR}/unknown-backend.log" 2>&1
unknown_backend_exit=$?
if [ "${unknown_backend_exit}" -ne 2 ]; then
  fail "unknown containment backend reported ${unknown_backend_exit} instead of a configuration error" "${TMP_DIR}/unknown-backend.log"
fi
if ! grep -q 'containment backend must be one of' "${TMP_DIR}/unknown-backend.log"; then
  fail 'unknown containment backend error did not name the accepted values' "${TMP_DIR}/unknown-backend.log"
fi
printf 'PASS: unknown containment backend is rejected as a configuration error\n'

# This suite is only worth writing if it runs where the backend is the default.
# Pin that: dropping the macOS job would silently return the repository to the
# state where no test could observe the Darwin path at all.
SELF_TEST_WORKFLOW="${ROOT_DIR}/.github/workflows/self-test.yml"
if ! grep -q 'runs-on: macos' "${SELF_TEST_WORKFLOW}"; then
  fail 'self-test workflow has no macOS job, so the portable backend is untested on its own platform'
fi
if ! grep -q 'scripts/core/test-portable-containment.sh' "${SELF_TEST_WORKFLOW}"; then
  fail 'self-test workflow does not run the portable containment suite'
fi
printf 'PASS: self-test workflow exercises the portable backend on a macOS runner\n'

python3 - "${SUPERVISOR}" <<'PY'
import importlib.util
import os
import sys

spec = importlib.util.spec_from_file_location("supervisor", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

# `auto` must never resolve to a backend whose primitives the platform lacks, and
# an explicit selection has to survive resolution so the macOS path stays under
# test on Linux runners.
assert module.resolve_backend("auto") == ("linux" if sys.platform == "linux" else "posix")
assert module.resolve_backend("posix") == "posix"
assert module.resolve_backend("linux") == "linux"
assert module.resolve_backend("cgroups") is None

table = {
    10: module.PosixProcess(ppid=1, pgid=10, state="S", start="Sat Aug 16 00:00:01 2026"),
    11: module.PosixProcess(ppid=10, pgid=10, state="S", start="Sat Aug 16 00:00:02 2026"),
    12: module.PosixProcess(ppid=1, pgid=10, state="S", start="Sat Aug 16 00:00:03 2026"),
    13: module.PosixProcess(ppid=11, pgid=99, state="S", start="Sat Aug 16 00:00:04 2026"),
    14: module.PosixProcess(ppid=1, pgid=99, state="S", start="Sat Aug 16 00:00:05 2026"),
    15: module.PosixProcess(ppid=1, pgid=10, state="Z", start="Sat Aug 16 00:00:06 2026"),
}
# 12 is a reparented descendant still carrying the command process group, 13 left
# the group but is still reachable through the parent chain, 14 is unrelated, and
# 15 is a zombie that can neither run nor hold the proof descriptor.
assert set(module.contained_processes(table, 10, 10)) == {10, 11, 12, 13}

# The `ps` keyword set has to parse identically under procps and BSD ps, and
# `lstart` is the one field containing spaces, so assert the split against the
# real process table rather than a fixture.
live_table = module.process_table()
assert live_table is not None
assert os.getpid() in live_table
assert live_table[os.getpid()].ppid == os.getppid()
assert live_table[os.getpid()].pgid == os.getpgid(0)
assert live_table[os.getpid()].start

read_fd, write_fd = os.pipe()
assert module.descriptor_is_released(read_fd) is False
os.close(write_fd)
assert module.descriptor_is_released(read_fd) is True
os.close(read_fd)
PY
python_units=$?
if [ "${python_units}" -ne 0 ]; then
  printf 'FAIL: portable containment helper assertions failed\n'
  exit 1
fi
printf 'PASS: backend resolution, process-table parsing, and descriptor proof hold\n'
