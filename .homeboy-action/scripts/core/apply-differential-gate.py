#!/usr/bin/env python3
"""Downgrade audit/test failures when PR metrics do not exceed base metrics."""

from __future__ import annotations

import json
import os
import sys
from hashlib import sha256
from typing import Any


def read_json(path: str) -> dict[str, Any] | None:
    try:
        with open(path, "r", encoding="utf-8") as handle:
            payload = json.load(handle)
        return payload if isinstance(payload, dict) else None
    except (OSError, json.JSONDecodeError):
        return None


def unwrap(payload: dict[str, Any] | None) -> dict[str, Any]:
    if not payload:
        return {}
    if "success" in payload and isinstance(payload.get("data"), dict):
        return payload["data"]
    return payload


def audit_count(payload: dict[str, Any] | None) -> int | None:
    data = unwrap(payload)
    summary = data.get("summary", {}) if isinstance(data, dict) else {}
    if isinstance(summary, dict) and isinstance(summary.get("outliers_found"), int):
        return int(summary["outliers_found"])

    findings = data.get("findings", []) if isinstance(data, dict) else []
    if isinstance(findings, list) and findings:
        return len(findings)

    conventions = data.get("conventions", []) if isinstance(data, dict) else []
    if isinstance(conventions, list):
        total = 0
        saw_conventions = False
        for convention in conventions:
            if not isinstance(convention, dict):
                continue
            saw_conventions = True
            outliers = convention.get("outliers", [])
            if isinstance(outliers, list):
                total += len(outliers)
        if saw_conventions:
            return total

    baseline = data.get("baseline_comparison", {}) if isinstance(data, dict) else {}
    new_items = baseline.get("new_items", []) if isinstance(baseline, dict) else []
    if isinstance(baseline, dict) and "new_items" in baseline and isinstance(new_items, list):
        return len(new_items)

    return None


def test_count(payload: dict[str, Any] | None) -> int | None:
    data = unwrap(payload)
    counts = data.get("test_counts", {}) if isinstance(data, dict) else {}
    if isinstance(counts, dict):
        failed = int(counts.get("failed", 0) or 0)
        errors = int(counts.get("errors", 0) or 0)
        if failed or errors or any(key in counts for key in ("failed", "errors")):
            return failed + errors

    failed_tests = data.get("failed_tests", []) if isinstance(data, dict) else []
    if isinstance(data, dict) and "failed_tests" in data and isinstance(failed_tests, list):
        return len(failed_tests)

    summary = data.get("summary", {}) if isinstance(data, dict) else {}
    if isinstance(summary, dict) and isinstance(summary.get("failures"), int):
        return int(summary["failures"])

    return None


def lint_count(payload: dict[str, Any] | None) -> int | None:
    data = unwrap(payload)
    if not isinstance(data, dict):
        return None

    for key in ("lint_findings", "findings", "violations", "top_violations"):
        items = data.get(key)
        if isinstance(items, list):
            return len(items)

    summary = data.get("summary")
    if isinstance(summary, dict):
        for key in ("findings", "failures", "errors"):
            value = summary.get(key)
            if isinstance(value, int):
                return int(value)

    return None


def quality_base_command(command: str) -> str:
    parts = command.split()
    if len(parts) == 1 and parts[0] in {"audit", "lint", "test"}:
        return parts[0]
    if len(parts) >= 2 and parts[0] == "review" and parts[1] in {"audit", "lint", "test"}:
        return parts[1]
    return ""


def changed_scope_introduced_count(command: str, payload: dict[str, Any] | None) -> int | None:
    data = unwrap(payload)
    changed_since = data.get("changed_since", {}) if isinstance(data, dict) else {}
    if not isinstance(changed_since, dict):
        return None

    key = "introduced_findings" if command == "audit" else "introduced_failures"
    value = changed_since.get(key)
    return int(value) if isinstance(value, int) else None


def output_stem(command: str) -> str:
    return "".join(ch if ch.isalnum() or ch in "._-" else "-" for ch in command).strip("-") or "homeboy-output"


def test_evidence_command_identity(command: str) -> str:
    parts = command.split()
    if len(parts) >= 2 and parts[:2] == ["review", "test"]:
        return "review test"
    if parts and parts[0] == "test":
        return "test"
    return command.strip()


# How a metric was derived. The distinction is load-bearing for the zero guard
# in main(): zero means opposite things depending on provenance.
#
#   SCOPED -- `changed_since.introduced_{findings,failures}`. A deliberate
#             measurement of what *this change* introduced. Zero is the
#             success case: the repository may be red, but the candidate added
#             nothing to it. This is the entire point of changed-scope gating.
#   TOTAL  -- a raw count of everything the command found. Zero here, on a
#             command that nonetheless failed or timed out, is not a
#             measurement of success; it means the failure went uncounted.
SCOPED = "scoped"
TOTAL = "total"


def metric_for(command: str, directory: str) -> tuple[int | None, str | None]:
    base_command = quality_base_command(command)
    payload = read_json(os.path.join(directory, f"{output_stem(command)}.json"))
    scoped_count = changed_scope_introduced_count(base_command, payload)
    if scoped_count is not None:
        return scoped_count, SCOPED
    if base_command == "audit":
        value = audit_count(payload)
    elif base_command == "lint":
        value = lint_count(payload)
    elif base_command == "test":
        value = test_count(payload)
    else:
        return None, None
    return (value, TOTAL) if value is not None else (None, None)


def base_command_status(command: str, base_dir: str) -> dict[str, Any]:
    status = read_json(os.path.join(base_dir, "baseline-status.json"))
    if not isinstance(status, dict):
        return {}
    value = status.get(command)
    return value if isinstance(value, dict) else {}


def test_outcomes(command: str, directory: str) -> tuple[str, set[str] | None, set[str] | None]:
    """Read Homeboy-owned per-test outcome and inventory sidecars.

    The inventory makes an empty failure set meaningful. Outcome evidence names
    only failures observed by the adapter; it never invents pass or skip facts.
    """
    stem = output_stem(command)
    evidence_command = test_evidence_command_identity(command)
    outcomes = read_json(os.path.join(directory, f"{stem}.test-outcomes.json"))
    inventory = read_json(os.path.join(directory, f"{stem}.test-inventory.json"))
    if outcomes is None or inventory is None:
        return "missing", None, None
    tests = inventory.get("tests") if isinstance(inventory, dict) else None
    failed_ids = outcomes.get("failed_test_ids") if isinstance(outcomes, dict) else None
    provenance = ("runner", "runner_fingerprint", "workspace_fingerprint", "execution_fingerprint")
    if (
        not isinstance(outcomes, dict)
        or not isinstance(inventory, dict)
        or outcomes.get("schema") != "homeboy/test-outcomes/v1"
        or inventory.get("schema") != "homeboy/test-inventory/v1"
        or outcomes.get("command") != evidence_command
        or inventory.get("command") != evidence_command
        or any(not isinstance(inventory.get(key), str) or not inventory[key] for key in provenance)
        or any(not isinstance(outcomes.get(key), str) or outcomes[key] != inventory[key] for key in provenance)
        or not isinstance(inventory.get("inventory_fingerprint"), str)
        or not isinstance(outcomes.get("inventory_fingerprint"), str)
        or outcomes["inventory_fingerprint"] != inventory["inventory_fingerprint"]
        or not isinstance(tests, list)
        or not isinstance(failed_ids, list)
    ):
        return "invalid", None, None
    inventory_ids = [item.get("id") for item in tests if isinstance(item, dict)]
    if (
        len(inventory_ids) != len(tests)
        or not inventory_ids
        or any(not isinstance(value, str) or not value for value in inventory_ids + failed_ids)
        or len(set(inventory_ids)) != len(inventory_ids)
        or len(set(failed_ids)) != len(failed_ids)
        or not set(failed_ids).issubset(inventory_ids)
    ):
        return "invalid", None, None
    canonical_inventory = {
        "command": evidence_command,
        "execution_fingerprint": inventory["execution_fingerprint"],
        "runner": inventory["runner"],
        "runner_fingerprint": inventory["runner_fingerprint"],
        "schema": "homeboy/test-inventory/v1",
        "tests": [{"id": identity} for identity in sorted(inventory_ids)],
        "workspace_fingerprint": inventory["workspace_fingerprint"],
    }
    expected_fingerprint = sha256(
        json.dumps(canonical_inventory, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
    if (
        any(len(inventory[key]) != 64 or any(char not in "0123456789abcdef" for char in inventory[key]) for key in ("runner_fingerprint", "workspace_fingerprint", "execution_fingerprint", "inventory_fingerprint"))
        or inventory["inventory_fingerprint"] != expected_fingerprint
    ):
        return "invalid", None, None
    return "complete", set(inventory_ids), set(failed_ids)


def test_evidence_defect(command: str, directory: str) -> str | None:
    """The producer's own account of why its sidecars are not evidence.

    Homeboy writes `{"invalid_evidence": {"type": ..., "reason": ...}}` in place
    of the outcome/inventory bodies when the run could not produce them, and that
    reason names the failing producer or field. Reading it costs nothing and is
    the difference between "the sidecars are malformed" -- which sent
    `Extra-Chill/extrachill-users#377` through two identical rerun cycles with
    nothing to act on -- and naming the defect. See Extra-Chill/homeboy#13494.
    """
    stem = output_stem(command)
    for suffix in ("test-outcomes", "test-inventory"):
        payload = read_json(os.path.join(directory, f"{stem}.{suffix}.json"))
        declared = payload.get("invalid_evidence") if isinstance(payload, dict) else None
        reason = declared.get("reason") if isinstance(declared, dict) else None
        if isinstance(reason, str) and reason.strip():
            return reason.strip()
    return None


def invalid_evidence_detail(command: str, sides: list[tuple[str, str, str]]) -> str:
    """Name the invalid side(s), and why, when the producer said why."""
    described = []
    for label, directory, evidence in sides:
        if evidence != "invalid":
            continue
        reason = test_evidence_defect(command, directory)
        described.append(f"{label}: {reason}" if reason else label)
    return f" ({'; '.join(described)})" if described else ""


def shared_test_evidence_defect(command: str, current_dir: str, base_dir: str) -> str | None:
    """Return a producer-declared defect only when both sides report it exactly."""
    current = test_evidence_defect(command, current_dir)
    baseline = test_evidence_defect(command, base_dir)
    return current if current and current == baseline else None


def command_label(command: str, metadata: dict[str, Any]) -> str:
    full = metadata.get("command")
    if isinstance(full, str) and full:
        return full
    return f"homeboy {command}"


def command_failed(metadata: dict[str, Any]) -> bool:
    if metadata.get("status") == "fail":
        return True
    try:
        return int(metadata.get("exit_code") or 0) != 0
    except (TypeError, ValueError):
        return False


def main() -> int:
    if len(sys.argv) != 4:
        print("usage: apply-differential-gate.py RESULTS_JSON CURRENT_DIR BASE_DIR", file=sys.stderr)
        return 2

    results = json.loads(sys.argv[1] or "{}")
    current_dir = sys.argv[2]
    base_dir = sys.argv[3]

    if not isinstance(results, dict):
        print(sys.argv[1])
        return 0

    adjusted = dict(results)
    for command, status in list(adjusted.items()):
        base_command = quality_base_command(command)
        if base_command not in {"audit", "lint", "test"}:
            continue
        # A timeout is admitted here so that a candidate which ran out of clock
        # can still be recognised as pre-existing when the baseline ran out of
        # clock too. Before this, `timeout` skipped the gate entirely and stayed
        # a hard red even when main was equally unable to finish, which is the
        # one thing differential gating exists to rule out.
        if status not in {"fail", "timeout"}:
            continue

        timed_out = status == "timeout"

        current, current_kind = metric_for(command, current_dir)
        base, _base_kind = metric_for(command, base_dir)
        base_metadata = base_command_status(command, base_dir)
        base_failed = command_failed(base_metadata)
        base_structured = base_metadata.get("structured_output") is True

        if base_command == "test":
            current_evidence, current_inventory, current_failed = test_outcomes(command, current_dir)
            base_evidence, base_inventory, base_failed = test_outcomes(command, base_dir)
            if "invalid" in {current_evidence, base_evidence}:
                shared_defect = None
                if current_evidence == base_evidence == "invalid":
                    shared_defect = shared_test_evidence_defect(command, current_dir, base_dir)
                if shared_defect:
                    adjusted[command] = "inconclusive"
                    run_id = os.environ.get("GITHUB_RUN_ID", "").strip()
                    retry = f" Retry after repairing the producer: gh run rerun {run_id} --failed." if run_id else ""
                    print(
                        f"::warning::Differential gate marked {command} inconclusive: candidate and baseline "
                        f"declared the same test evidence producer defect: {shared_defect}.{retry}",
                        file=sys.stderr,
                    )
                    continue
                adjusted[command] = "invalid_evidence"
                detail = invalid_evidence_detail(
                    command,
                    [
                        ("candidate", current_dir, current_evidence),
                        ("baseline", base_dir, base_evidence),
                    ],
                )
                print(
                    f"::error::Differential gate rejected {command}: type=invalid_evidence; "
                    "Homeboy per-test outcome/inventory sidecars are malformed, duplicate, or "
                    f"provenance-mismatched{detail}.",
                    file=sys.stderr,
                )
                continue
            if current_evidence != "complete" or base_evidence != "complete":
                adjusted[command] = "no_comparable_evidence"
                print(
                    f"::error::Differential gate rejected {command}: type=no_comparable_evidence; "
                    "both failed phases require complete Homeboy per-test outcome and inventory sidecars.",
                    file=sys.stderr,
                )
                continue
            assert current_inventory is not None and current_failed is not None
            assert base_inventory is not None and base_failed is not None
            if not current_failed:
                adjusted[command] = "invalid_evidence"
                print(
                    f"::error::Differential gate rejected {command}: type=invalid_evidence; "
                    "the candidate command failed but its complete outcome evidence contains no failed identity.",
                    file=sys.stderr,
                )
                continue
            candidate_only_inventory = current_inventory - base_inventory
            baseline_only_inventory = base_inventory - current_inventory
            introduced = current_failed - base_failed
            if introduced:
                print(
                    f"::error::Differential gate rejected {command}: {len(introduced)} candidate-only failed test identity(s).",
                    file=sys.stderr,
                )
                continue
            adjusted[command] = "baseline_red"
            if candidate_only_inventory:
                print(
                    f"::notice::Differential gate admitted {len(candidate_only_inventory)} "
                    f"passing candidate-only test identity(s) for {command}.",
                    file=sys.stderr,
                )
            if baseline_only_inventory:
                print(
                    f"::warning::Differential gate marked {command} baseline_red: "
                    f"{len(baseline_only_inventory)} baseline-only inventory identity(s) drifted or were flaky; "
                    "they were excluded from introduced-failure attribution.",
                    file=sys.stderr,
                )
            else:
                print(
                    f"::warning::Differential gate marked {command} baseline_red: all candidate failed identities reproduce on the baseline.",
                    file=sys.stderr,
                )
            continue

        if base_failed and (base is None or not base_structured):
            # `baseline_red` asserts something specific: this failure is
            # pre-existing. That claim needs an observation on the candidate
            # side to rest on -- "the same thing is wrong on main" presumes we
            # know what is wrong here.
            #
            # When the candidate also produced no measurement, there is no such
            # observation. A double timeout is the concrete case: both sides ran
            # out of clock, neither wrote counts, and nothing at all is known
            # about the command. Reporting that as `baseline_red` overstates it,
            # and it is indistinguishable in the results JSON from a genuine
            # measured-and-unchanged failure.
            #
            # `no_measurement` is deliberately non-blocking, exactly like
            # `baseline_red`: a candidate is still not answerable for an
            # infrastructure condition it did not cause, which is the decision
            # the "timeout that also times out on the baseline" case encodes.
            # This only stops the two states from being reported as one, so the
            # absence of evidence is visible and countable rather than dressed
            # up as a diagnosis. See Extra-Chill/homeboy#10999.
            if timed_out or current is None:
                adjusted[command] = "no_measurement"
                print(
                    f"::warning::Differential gate marked {command} no_measurement: neither the candidate nor the baseline produced comparable counts (baseline command `{command_label(command, base_metadata)}` exited {base_metadata.get('exit_code', 'unknown')}). Nothing is known about this command -- this is not evidence that the failure is pre-existing.",
                    file=sys.stderr,
                )
                continue

            adjusted[command] = "baseline_red"
            print(
                f"::warning::Differential gate marked {command} baseline_red: baseline command `{command_label(command, base_metadata)}` exited {base_metadata.get('exit_code', 'unknown')} before comparable counts were available",
                file=sys.stderr,
            )
            continue

        # Past this point the baseline is healthy, so an incomplete candidate
        # run is the candidate's problem and must keep blocking.
        #
        # This guard must precede every count-based branch below. Counts from a
        # suite that was killed mid-run are not comparable to counts from one
        # that finished: a timeout typically reports *fewer* failures than the
        # baseline (often zero, because the results sidecar was never written),
        # so `current <= base` would read as an improvement and silently mark
        # the command `pass`. Turning "the suite never finished" into a green
        # gate is a strictly worse failure than the false red this change
        # exists to remove. `inconclusive` is equally wrong here -- it only
        # warns, and a timeout against a healthy baseline is actionable.
        if timed_out:
            print(
                f"::error::Differential gate kept {command} as timeout: the candidate run did not "
                f"finish, so its counts are not comparable to the baseline. Raise the command's "
                f"execution budget or reduce suite duration; do not read this as a test failure.",
                file=sys.stderr,
            )
            continue

        if current is None or base is None:
            adjusted[command] = "inconclusive"
            print(
                f"::warning::Differential gate marked {command} inconclusive: current={current if current is not None else 'unavailable'} base={base if base is not None else 'unavailable'}",
                file=sys.stderr,
            )
            continue

        if current > base:
            print(
                f"::error::Differential gate rejected {command}: current={current} base={base}",
                file=sys.stderr,
            )
            continue

        # `current == base` with something still red is not an improvement, and
        # `pass` is an actively false statement about it: it renders a green
        # check with no annotation anywhere, so a command that is red on the
        # base branch stays red forever because no run ever reports it.
        # `baseline_red` says the true thing -- this is pre-existing -- and
        # still only warns in enforce-final-status.sh, so no PR that was
        # previously mergeable becomes blocked. See Extra-Chill/homeboy#10657.
        if current == base and base > 0:
            adjusted[command] = "baseline_red"
            print(
                f"::warning::Differential gate marked {command} baseline_red: "
                f"{current} failure(s) reproduce unchanged on the baseline "
                f"(current={current} base={base}). Nothing regressed, but nothing "
                f"improved either -- this command is red on the base branch.",
                file=sys.stderr,
            )
            continue

        # Zero total findings on a command that failed or timed out is not a
        # clean result; it is an uncounted failure. A killed run is the concrete
        # case: the child dies before writing its results sidecar, `test_counts`
        # still carries `failed: 0`, and `0 <= base` would read a suite that
        # never finished as a clean sweep. Compile errors and harness crashes
        # land here too -- the command failed for a reason the counts do not
        # describe, so the counts cannot license a pass.
        #
        # A genuine "fixed the last failure" run cannot reach this branch: it
        # exits 0, is classified `pass` upstream, and never enters the gate.
        #
        # SCOPED is deliberately exempt -- there, zero is the measurement, not
        # the absence of one. See Extra-Chill/homeboy#10685.
        if current == 0 and current_kind != SCOPED:
            adjusted[command] = "inconclusive"
            print(
                f"::warning::Differential gate marked {command} inconclusive: the command "
                f"failed but reported 0 findings/failures (base={base}), so its own failure "
                f"is uncounted. An incomplete, killed, or non-reporting run is never "
                f"accepted as an improvement.",
                file=sys.stderr,
            )
            continue

        adjusted[command] = "pass"
        print(
            f"::notice::Differential gate accepted {command}: current={current} base={base}",
            file=sys.stderr,
        )

    print(json.dumps(adjusted, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
