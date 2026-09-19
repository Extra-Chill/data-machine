#!/usr/bin/env python3
"""Exercise Homeboy-owned per-test outcome/inventory reconciliation fixtures."""

from __future__ import annotations

import hashlib
import json
import shutil
import subprocess
import tempfile
from pathlib import Path


ROOT = Path(__file__).parent
GATE = ROOT / "apply-differential-gate.py"
FIXTURES = ROOT / "fixtures" / "differential-test-outcomes"
COMMAND = "review test"
STEM = "review-test"
BASELINE_STATUS = {COMMAND: {"status": "fail", "exit_code": 1, "structured_output": True}}


def run_gate(current: Path, baseline: Path, command: str = COMMAND) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["python3", str(GATE), json.dumps({command: "fail"}), str(current), str(baseline)],
        check=True,
        text=True,
        capture_output=True,
    )


def gate(current: Path, baseline: Path, command: str = COMMAND) -> dict:
    return json.loads(run_gate(current, baseline, command).stdout)


def install_pair(directory: Path, prefix: str) -> None:
    shutil.copy(FIXTURES / f"{prefix}-outcomes.json", directory / f"{STEM}.test-outcomes.json")
    shutil.copy(FIXTURES / f"{prefix}-inventory.json", directory / f"{STEM}.test-inventory.json")


def write_inventory(directory: Path, payload: dict) -> None:
    canonical = {
        "command": payload["command"],
        "execution_fingerprint": payload["execution_fingerprint"],
        "runner": payload["runner"],
        "runner_fingerprint": payload["runner_fingerprint"],
        "schema": payload["schema"],
        "tests": sorted(payload["tests"], key=lambda item: item["id"]),
        "workspace_fingerprint": payload["workspace_fingerprint"],
    }
    payload["inventory_fingerprint"] = hashlib.sha256(
        json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
    (directory / f"{STEM}.test-inventory.json").write_text(json.dumps(payload), encoding="utf-8")

    outcomes_path = directory / f"{STEM}.test-outcomes.json"
    outcomes = json.loads(outcomes_path.read_text(encoding="utf-8"))
    outcomes["inventory_fingerprint"] = payload["inventory_fingerprint"]
    outcomes_path.write_text(json.dumps(outcomes), encoding="utf-8")


def main() -> None:
    with tempfile.TemporaryDirectory() as temporary:
        root = Path(temporary)
        current, baseline = root / "current", root / "baseline"
        current.mkdir()
        baseline.mkdir()
        (baseline / "baseline-status.json").write_text(json.dumps(BASELINE_STATUS), encoding="utf-8")

        install_pair(current, "13290-candidate")
        install_pair(baseline, "13290-baseline")
        assert gate(current, baseline) == {COMMAND: "fail"}, "#13290 nondeterministic identity must block"

        payload = json.loads((baseline / f"{STEM}.test-outcomes.json").read_text(encoding="utf-8"))
        payload["failed_test_ids"] = ["nondeterministic", "stable"]
        (baseline / f"{STEM}.test-outcomes.json").write_text(json.dumps(payload), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "baseline_red"}, "#435 equivalent failures must reconcile"

        inventory = json.loads((current / f"{STEM}.test-inventory.json").read_text(encoding="utf-8"))
        inventory["tests"].append({"id": "new_passing_test"})
        write_inventory(current, inventory)
        assert gate(current, baseline) == {COMMAND: "baseline_red"}, "#449 passing inventory growth must reconcile"

        outcomes_path = current / f"{STEM}.test-outcomes.json"
        outcomes = json.loads(outcomes_path.read_text(encoding="utf-8"))
        outcomes["failed_test_ids"].append("new_passing_test")
        outcomes_path.write_text(json.dumps(outcomes), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "fail"}, "#449 a newly failing added test must block"

        for path in current.iterdir():
            path.unlink()
        for path in baseline.iterdir():
            if path.name != "baseline-status.json":
                path.unlink()
        shutil.copy(FIXTURES / "1195-candidate-result.json", current / f"{STEM}.json")
        shutil.copy(FIXTURES / "1195-baseline-result.json", baseline / f"{STEM}.json")
        assert gate(current, baseline) == {COMMAND: "no_comparable_evidence"}, "#1195 must fail closed"

        install_pair(current, "13290-candidate")
        install_pair(baseline, "13290-baseline")
        payload = json.loads((current / f"{STEM}.test-outcomes.json").read_text(encoding="utf-8"))
        payload["failed_test_ids"].append(payload["failed_test_ids"][0])
        (current / f"{STEM}.test-outcomes.json").write_text(json.dumps(payload), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "invalid_evidence"}, "duplicate identities must fail closed"

        install_pair(current, "13290-candidate")
        payload = json.loads((current / f"{STEM}.test-inventory.json").read_text(encoding="utf-8"))
        payload["inventory_fingerprint"] = "0" * 64
        (current / f"{STEM}.test-inventory.json").write_text(json.dumps(payload), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "invalid_evidence"}, "arbitrary inventory fingerprints must fail closed"

        install_pair(current, "13290-candidate")
        payload = json.loads((current / f"{STEM}.test-outcomes.json").read_text(encoding="utf-8"))
        payload["failed_test_ids"] = []
        (current / f"{STEM}.test-outcomes.json").write_text(json.dumps(payload), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "invalid_evidence"}, "failed commands need a failed candidate identity"

        install_pair(current, "13290-candidate")
        install_pair(baseline, "13290-baseline")
        suffixed = "review test package-a"
        suffixed_stem = "review-test-package-a"
        for directory in (current, baseline):
            for kind in ("inventory", "outcomes"):
                (directory / f"{STEM}.test-{kind}.json").rename(directory / f"{suffixed_stem}.test-{kind}.json")
        (baseline / "baseline-status.json").write_text(
            json.dumps({suffixed: BASELINE_STATUS[COMMAND]}), encoding="utf-8"
        )
        assert gate(current, baseline, suffixed) == {suffixed: "fail"}, "suffixed commands use canonical sidecar identity"

        bare_suffixed = "test package-a"
        bare_stem = "test-package-a"
        for directory in (current, baseline):
            inventory_path = directory / f"{suffixed_stem}.test-inventory.json"
            outcomes_path = directory / f"{suffixed_stem}.test-outcomes.json"
            inventory = json.loads(inventory_path.read_text(encoding="utf-8"))
            outcomes = json.loads(outcomes_path.read_text(encoding="utf-8"))
            inventory["command"] = "test"
            canonical = {key: value for key, value in inventory.items() if key != "inventory_fingerprint"}
            inventory["inventory_fingerprint"] = hashlib.sha256(
                json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
            ).hexdigest()
            outcomes["command"] = "test"
            outcomes["inventory_fingerprint"] = inventory["inventory_fingerprint"]
            inventory_path.rename(directory / f"{bare_stem}.test-inventory.json")
            outcomes_path.rename(directory / f"{bare_stem}.test-outcomes.json")
            (directory / f"{bare_stem}.test-inventory.json").write_text(json.dumps(inventory), encoding="utf-8")
            (directory / f"{bare_stem}.test-outcomes.json").write_text(json.dumps(outcomes), encoding="utf-8")
        (baseline / "baseline-status.json").write_text(
            json.dumps({bare_suffixed: BASELINE_STATUS[COMMAND]}), encoding="utf-8"
        )
        assert gate(current, baseline, bare_suffixed) == {bare_suffixed: "fail"}, "bare suffixed Test uses canonical sidecar identity"

        # A producer that could not produce evidence says why, in the sidecar it
        # writes instead. The gate must repeat that reason: rejecting a PR with
        # "the sidecars are malformed" and nothing else is what made
        # Extra-Chill/extrachill-users#377 unactionable across two rerun cycles.
        # Extra-Chill/homeboy#13494.
        for path in current.iterdir():
            path.unlink()
        for path in baseline.iterdir():
            if path.name != "baseline-status.json":
                path.unlink()
        install_pair(current, "13290-candidate")
        install_pair(baseline, "13290-baseline")
        (baseline / "baseline-status.json").write_text(
            json.dumps(BASELINE_STATUS), encoding="utf-8"
        )
        reason = "internal runtime inventory producer emitted invalid evidence: test inventory workspace provenance did not match the bound workspace"
        for kind, schema in (("outcomes", "homeboy/test-outcomes/v1"), ("inventory", "homeboy/test-inventory/v1")):
            (current / f"{STEM}.test-{kind}.json").write_text(
                json.dumps({
                    "schema": schema,
                    "command": COMMAND,
                    "invalid_evidence": {"type": "invalid_evidence", "reason": reason},
                }),
                encoding="utf-8",
            )
        completed = run_gate(current, baseline)
        assert json.loads(completed.stdout) == {COMMAND: "invalid_evidence"}, "declared invalid evidence must fail closed"
        assert "candidate: " + reason in completed.stderr, completed.stderr
        assert "baseline" not in completed.stderr.split("provenance-mismatched")[1], completed.stderr

        for directory in (current, baseline):
            for kind, schema in (("outcomes", "homeboy/test-outcomes/v1"), ("inventory", "homeboy/test-inventory/v1")):
                (directory / f"{STEM}.test-{kind}.json").write_text(
                    json.dumps({
                        "schema": schema,
                        "command": COMMAND,
                        "invalid_evidence": {"type": "invalid_evidence", "reason": reason},
                    }),
                    encoding="utf-8",
                )
        completed = run_gate(current, baseline)
        assert json.loads(completed.stdout) == {COMMAND: "inconclusive"}, "identical declared producer defects are shared infrastructure"
        assert "candidate and baseline declared the same" in completed.stderr, completed.stderr
        assert reason in completed.stderr, completed.stderr

        baseline_outcomes = baseline / f"{STEM}.test-outcomes.json"
        payload = json.loads(baseline_outcomes.read_text(encoding="utf-8"))
        payload["invalid_evidence"]["reason"] = reason + " on baseline"
        baseline_outcomes.write_text(json.dumps(payload), encoding="utf-8")
        assert gate(current, baseline) == {COMMAND: "invalid_evidence"}, "different producer defects must fail closed"

    print("PASS: Test outcome/inventory reconciliation fixtures")


if __name__ == "__main__":
    main()
