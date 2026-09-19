#!/usr/bin/env python3
"""Strict command supervisor with platform-specific descendant containment.

Two backends, because the kernel primitives that make containment provable are
not portable:

`linux` — `PR_SET_CHILD_SUBREAPER` reparents every orphaned descendant onto the
supervisor, `/proc` walks the resulting tree, and pidfds signal it without a
PID-reuse window. This is the strongest guarantee available and stays the
default on Linux runners.

`posix` — Darwin (and any other POSIX host) has no subreaper and no pidfd, so
the same guarantee cannot be reconstructed from the process table alone: a
descendant that double-forks and calls `setsid` leaves both the process group
and the parent chain with no portable trace. The portable backend therefore
splits the two jobs the Linux backend fuses:

  * *Termination* uses the command's own session/process group (`killpg`) plus
    the transitive parent chain, which covers every descendant that did not
    deliberately detach.
  * *Proof* uses an inherited pipe. The command is spawned holding the write end
    of a pipe the supervisor keeps the read end of, and every descendant
    inherits that descriptor across `fork` and `exec`. EOF on the read end is
    positive evidence that no descendant is left holding it — including one that
    detached itself out of reach of `killpg`. No EOF inside the cleanup budget is
    reported as a containment failure (exit 125) rather than laundered into a
    pass.

The pipe is evidence, not a leash: the supervisor cannot signal a descendant it
cannot name. What it can do is refuse to certify a command whose descendants
outlived it, which is the property strict mode exists to protect.

`HOMEBOY_ACTION_CONTAINMENT_BACKEND` selects the backend explicitly. The default
is `auto`, which picks by platform; naming `posix` is how the portable backend
stays under test on Linux runners instead of only on the macOS ones that depend
on it.
"""

import argparse
import collections
import ctypes
import os
import select
import signal
import subprocess
import sys
import time

PR_SET_CHILD_SUBREAPER = 36

LINUX_BACKEND = "linux"
POSIX_BACKEND = "posix"
AUTO_BACKEND = "auto"
BACKENDS = (AUTO_BACKEND, LINUX_BACKEND, POSIX_BACKEND)

BACKEND_GUARANTEE = {
    LINUX_BACKEND: "subreaper-reparented pidfd containment",
    POSIX_BACKEND: "session-scoped termination with inherited-descriptor containment proof",
}


LinuxProcess = collections.namedtuple("LinuxProcess", ("ppid", "state", "start", "command"))


def linux_process(pid, include_command=False):
    try:
        stat = open(f"/proc/{pid}/stat", encoding="utf-8").read()
        closing_parenthesis = stat.rfind(")")
        if closing_parenthesis < 0:
            return None
        fields = stat[closing_parenthesis + 2 :].split()
        state, ppid, start = fields[0], int(fields[1]), int(fields[19])
        command = stat[stat.find("(") + 1 : closing_parenthesis]
        if include_command:
            try:
                cmdline = open(f"/proc/{pid}/cmdline", "rb").read().replace(b"\0", b" ").strip()
                if cmdline:
                    command = cmdline.decode("utf-8", errors="replace")
            except (FileNotFoundError, ProcessLookupError, PermissionError):
                pass
        return LinuxProcess(ppid=ppid, state=state, start=start, command=command)
    except (FileNotFoundError, ProcessLookupError, IndexError, ValueError):
        return None


def identity(pid):
    entry = linux_process(pid)
    return None if entry is None or entry.state == "Z" else entry.start


def descendants(root):
    children = {}
    for entry in os.scandir("/proc"):
        if not entry.name.isdigit():
            continue
        try:
            process = linux_process(int(entry.name))
            if process is not None:
                children.setdefault(process.ppid, []).append(int(entry.name))
        except PermissionError:
            # A process that exits between scandir and read surfaces as ESRCH
            # (ProcessLookupError), not just ENOENT. Scanning all of /proc means
            # this race is routine; letting it escape kills the supervisor and
            # orphans the very descendants it exists to contain.
            continue
    result, pending = set(), [root]
    while pending:
        for child in children.get(pending.pop(), []):
            if child not in result:
                result.add(child)
                pending.append(child)
    return result


def open_pidfd(pid):
    try:
        return os.pidfd_open(pid, 0)
    except ProcessLookupError:
        return None


def signal_pidfd(pidfd, signal_number):
    try:
        signal.pidfd_send_signal(pidfd, signal_number)
        return True
    except ProcessLookupError:
        return True
    except PermissionError:
        return False


def known_live(known):
    try:
        return any(identity(pid) == start for pid, start in known)
    except PermissionError:
        return None


def live_member_details(known):
    members = []
    for pid, start in known:
        entry = linux_process(pid, include_command=True)
        if entry is None or entry.state == "Z" or entry.start != start:
            continue
        ancestry, parent = [pid], entry.ppid
        while parent > 0 and parent not in ancestry:
            ancestry.append(parent)
            parent_entry = linux_process(parent)
            if parent_entry is None:
                break
            parent = parent_entry.ppid
        members.append((pid, entry, "<-".join(str(member) for member in ancestry)))
    return members


def report_live_members(label, known):
    for pid, entry, ancestry in live_member_details(known):
        command = entry.command.replace("\r", " ").replace("\n", " ")[:500]
        print(
            f"::warning::{label} live containment member: pid={pid} state={entry.state} "
            f"command={command!r} ancestry={ancestry}."
        )


def reap_children():
    while True:
        try:
            pid, _ = os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            return
        if pid == 0:
            return


def close_pidfds(known):
    for pidfd in known.values():
        try:
            os.close(pidfd)
        except OSError:
            pass


PosixProcess = collections.namedtuple("PosixProcess", ("ppid", "pgid", "state", "start"))

PS_ARGUMENTS = ("ps", "-axo", "pid=,ppid=,pgid=,state=,lstart=")


def process_table():
    """Snapshot every visible process, or `None` when `ps` cannot be trusted.

    `lstart` is last because it is the only field containing spaces, so a
    bounded split keeps the parse unambiguous. It is the closest portable stand
    in for the Linux start-ticks identity: pairing it with the PID means a
    recycled PID has to be reused inside the same wall-clock second to be
    mistaken for the process it replaced.
    """
    try:
        completed = subprocess.run(list(PS_ARGUMENTS), capture_output=True, text=True, check=False)
    except OSError:
        return None
    if completed.returncode != 0:
        return None
    table = {}
    for line in completed.stdout.splitlines():
        fields = line.split(None, 4)
        if len(fields) < 5:
            continue
        try:
            pid, ppid, pgid = int(fields[0]), int(fields[1]), int(fields[2])
        except ValueError:
            continue
        table[pid] = PosixProcess(ppid=ppid, pgid=pgid, state=fields[3], start=fields[4].strip())
    return table


def is_live_entry(entry):
    # A zombie holds a PID but cannot run, hold a descriptor, or receive a
    # signal. Counting one as live would spin cleanup until the grace expired.
    return not entry.state.startswith("Z")


def parent_chain_descendants(table, root):
    children = {}
    for pid, entry in table.items():
        children.setdefault(entry.ppid, []).append(pid)
    result, pending = set(), [root]
    while pending:
        for child in children.get(pending.pop(), []):
            if child not in result:
                result.add(child)
                pending.append(child)
    return result


def contained_processes(table, command_pid, command_pgid):
    """Live descendants reachable by process group or by parent chain.

    Neither source is sufficient alone. A double-forked descendant is reparented
    to init, so only its inherited process group still names it; a descendant
    that called `setpgid` for its own job control is only reachable through the
    parent chain.

    The chain is walked from the command, never from the supervisor. The
    supervisor's own children include the `ps` invocation taking this very
    snapshot, and counting that as leaked containment made every command look
    like it leaked.
    """
    chain = parent_chain_descendants(table, command_pid)
    tracked = {}
    for pid, entry in table.items():
        if not is_live_entry(entry):
            continue
        if entry.pgid == command_pgid or pid in chain:
            tracked[pid] = entry
    return tracked


def descriptor_is_released(read_fd):
    """`True` once no process holds the inherited write end of the proof pipe.

    Nothing ever writes to the pipe, so a readable read end can only mean the
    last write end closed. That is the one containment signal on this platform
    that a descendant cannot evade by detaching from our process group.
    """
    try:
        readable, _, _ = select.select([read_fd], [], [], 0)
    except OSError:
        return None
    if not readable:
        return False
    try:
        return os.read(read_fd, 1) == b""
    except BlockingIOError:
        return False
    except OSError:
        return None


def signal_posix_containment(table, tracked, command_pgid, signal_number):
    try:
        os.killpg(command_pgid, signal_number)
    except ProcessLookupError:
        pass
    except PermissionError:
        return False
    for pid, entry in tracked.items():
        if entry.pgid == command_pgid:
            continue
        # Re-read identity from the same snapshot the caller verified so a PID
        # recycled since the scan is not signalled in the original's place.
        current = table.get(pid)
        if current is None or current.start != entry.start:
            continue
        try:
            os.kill(pid, signal_number)
        except ProcessLookupError:
            continue
        except PermissionError:
            return False
    return True


def run_posix(args):
    output = open(args.log_file, "wb") if args.log_file else None
    proof_read, proof_write = os.pipe()
    os.set_inheritable(proof_write, True)
    try:
        process = subprocess.Popen(
            args.command,
            stdout=output,
            stderr=subprocess.STDOUT if output else None,
            preexec_fn=os.setsid,
            pass_fds=(proof_write,),
        )
    finally:
        # The supervisor holding a write end would make EOF unreachable, so this
        # close is what turns the pipe into a containment signal at all.
        os.close(proof_write)

    command_pgid = process.pid
    print(f"::notice::{args.label} is contained by {BACKEND_GUARANTEE[POSIX_BACKEND]} in process group {command_pgid}.")
    started, timed_out = time.monotonic(), False

    def live_containment():
        table = process_table()
        if table is None:
            return None, None
        return table, contained_processes(table, process.pid, command_pgid)

    def terminate(reason):
        print(f"::warning::{args.label} {reason}; terminating process group {command_pgid} and tracked descendants.")
        for signal_number, escalation in ((signal.SIGTERM, False), (signal.SIGKILL, True)):
            if escalation:
                print(f"::warning::{args.label} containment grace expired; sending SIGKILL to process group {command_pgid}.")
            table, tracked = live_containment()
            if table is None:
                return False
            if not signal_posix_containment(table, tracked, command_pgid, signal_number):
                return False
            deadline = time.monotonic() + args.cleanup_timeout
            while time.monotonic() < deadline:
                table, tracked = live_containment()
                if table is None:
                    return False
                if not tracked:
                    return True
                time.sleep(0.05)
        return False

    while process.poll() is None:
        elapsed = int(time.monotonic() - started)
        if elapsed >= args.timeout:
            timed_out = True
            print(f"::error::{args.label} exceeded its {args.timeout}s execution timeout.")
            if not terminate("timed out"):
                return 125
            break
        time.sleep(0.05)

    exit_code = process.wait()
    reap_children()
    table, tracked = live_containment()
    if table is None:
        print(f"::error::{args.label} could not read the process table to prove containment; refusing to certify this strict command.")
        return 125
    leaked_containment = False
    if tracked:
        leaked_containment = True
        if not terminate("finalization found live command containment"):
            return 125
    reap_children()

    # Termination only reaches descendants we can name. The inherited descriptor
    # is what closes the gap: it stays open in anything that detached from the
    # process group, so waiting for EOF proves the absence of an escapee no scan
    # of the process table can see.
    released = descriptor_is_released(proof_read)
    deadline = time.monotonic() + args.cleanup_timeout
    while released is False and time.monotonic() < deadline:
        time.sleep(0.05)
        released = descriptor_is_released(proof_read)
    os.close(proof_read)
    if output:
        output.close()
    if released is not True:
        print(
            f"::error::{args.label} could not prove descendant containment within {args.cleanup_timeout}s: "
            "a descendant detached from the command session and still holds its inherited descriptor. "
            f"Retained command output: {args.log_file or 'standard output'}."
        )
        return 125
    if timed_out:
        return 124
    if leaked_containment and exit_code == 0:
        print(f"::error::{args.label} leaked descendants that outlived the command; reporting containment failure.")
        return 124
    return exit_code


def resolve_backend(requested):
    if requested not in BACKENDS:
        return None
    if requested != AUTO_BACKEND:
        return requested
    return LINUX_BACKEND if sys.platform == "linux" else POSIX_BACKEND


def run_linux(args):
    if sys.platform != "linux":
        print(f"::error::{args.label} requires Linux pidfd containment; refusing to run this strict command.")
        return 125
    if not hasattr(os, "pidfd_open") or not hasattr(signal, "pidfd_send_signal"):
        print(f"::error::{args.label} requires Linux pidfd containment; refusing to run this strict command.")
        return 125
    try:
        probe = open_pidfd(os.getpid())
        if probe is None:
            return 125
        os.close(probe)
    except PermissionError:
        print(f"::error::{args.label} lacks permission for Linux pidfd containment; refusing to run this strict command.")
        return 125
    if ctypes.CDLL(None).prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0:
        print(f"::error::{args.label} cannot establish a Linux child subreaper; refusing to run this strict command.")
        return 125
    print(f"::notice::{args.label} is contained by {BACKEND_GUARANTEE[LINUX_BACKEND]}.")

    output = open(args.log_file, "wb") if args.log_file else None
    process = subprocess.Popen(args.command, stdout=output, stderr=subprocess.STDOUT if output else None, preexec_fn=os.setsid)
    known = {}
    root_start = identity(process.pid)
    try:
        root_pidfd = open_pidfd(process.pid) if root_start is not None else None
    except PermissionError:
        process.kill()
        process.wait()
        return 125
    if root_pidfd is not None:
        known[(process.pid, root_start)] = root_pidfd
    started, timed_out = time.monotonic(), False

    def observe():
        try:
            observed = descendants(os.getpid())
            for pid in observed:
                start = identity(pid)
                key = (pid, start)
                if start is not None and key not in known:
                    pidfd = open_pidfd(pid)
                    if pidfd is not None:
                        known[key] = pidfd
            return True
        except PermissionError:
            return False

    def terminate(reason):
        print(f"::warning::{args.label} {reason}; terminating supervised descendants by pidfd.")
        if not observe():
            return False
        for pidfd in known.values():
            if not signal_pidfd(pidfd, signal.SIGTERM):
                return False
        for signal_number, name in ((signal.SIGTERM, "TERM"), (signal.SIGKILL, "KILL")):
            deadline = time.monotonic() + args.cleanup_timeout
            while time.monotonic() < deadline:
                if not observe():
                    return False
                live = known_live(known)
                if live is False:
                    return True
                if live is None:
                    return False
                time.sleep(0.05)
            if signal_number == signal.SIGTERM:
                print(f"::warning::{args.label} containment grace expired; sending SIGKILL by pidfd.")
                for pidfd in known.values():
                    if not signal_pidfd(pidfd, signal.SIGKILL):
                        return False
        return False

    while process.poll() is None:
        if not observe():
            close_pidfds(known)
            return 125
        elapsed = int(time.monotonic() - started)
        if elapsed >= args.timeout:
            timed_out = True
            print(f"::error::{args.label} exceeded its {args.timeout}s execution timeout.")
            if not terminate("timed out"):
                close_pidfds(known)
                return 125
            break
        time.sleep(0.05)

    exit_code = process.wait()
    drain_deadline = time.monotonic() + args.cleanup_timeout
    while True:
        reap_children()
        if not observe():
            close_pidfds(known)
            return 125
        live = known_live(known)
        if live is None:
            close_pidfds(known)
            return 125
        if not live or time.monotonic() >= drain_deadline:
            break
        time.sleep(0.05)
    leaked_containment = False
    if live:
        leaked_containment = True
        report_live_members(args.label, known)
        if not terminate("finalization found live command containment"):
            close_pidfds(known)
            return 125
    reap_children()
    close_pidfds(known)
    if output:
        output.close()
    if timed_out:
        return 124
    # A command that outlived its own exit by leaking descendants we had to
    # force-terminate did not finish cleanly, whatever it reported. Strict mode
    # exists to prove containment, so refuse to launder that into a pass. A real
    # non-zero exit still wins: it is more actionable than a synthetic timeout.
    if leaked_containment and exit_code == 0:
        print(f"::error::{args.label} leaked descendants that outlived the command; reporting containment failure.")
        return 124
    return exit_code


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--log-file", default="")
    parser.add_argument("label")
    parser.add_argument("timeout", type=int)
    parser.add_argument("cleanup_timeout", type=int)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if not args.command:
        return 2
    requested = os.environ.get("HOMEBOY_ACTION_CONTAINMENT_BACKEND", AUTO_BACKEND).strip() or AUTO_BACKEND
    backend = resolve_backend(requested)
    if backend is None:
        print(f"::error::{args.label} containment backend must be one of {', '.join(BACKENDS)}; received '{requested}'.")
        return 2
    if backend == POSIX_BACKEND:
        return run_posix(args)
    return run_linux(args)


if __name__ == "__main__":
    raise SystemExit(main())
