#!/usr/bin/env python3

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "core" / "apply-differential-gate.py"


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload), encoding="utf-8")


def run_gate(results: dict, current: Path, base: Path) -> dict:
    completed = subprocess.run(
        ["python3", str(SCRIPT), json.dumps(results), str(current), str(base)],
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(completed.stdout)


def assert_equal(expected, actual, label: str) -> None:
    if expected != actual:
        raise AssertionError(f"{label}\nexpected: {expected!r}\nactual:   {actual!r}")
    print(f"PASS: {label}")


def main() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        current = root / "current"
        base = root / "base"
        current.mkdir()
        base.mkdir()

        write_json(base / "audit.json", {"success": False, "data": {"summary": {"outliers_found": 5}}})
        write_json(current / "audit.json", {"success": False, "data": {"summary": {"outliers_found": 5}}})
        assert_equal(
            {"audit": "baseline_red"},
            run_gate({"audit": "fail"}, current, base),
            "audit failure that reproduces unchanged on the baseline is baseline_red",
        )

        write_json(current / "audit.json", {"success": False, "data": {"summary": {"outliers_found": 6}}})
        assert_equal(
            {"audit": "fail"},
            run_gate({"audit": "fail"}, current, base),
            "audit failure remains when outliers increase",
        )

        write_json(
            current / "audit.json",
            {
                "success": False,
                "data": {
                    "summary": {"outliers_found": 6},
                    "changed_since": {"introduced_findings": 0, "contextual_findings": 6},
                },
            },
        )
        assert_equal(
            {"audit": "pass"},
            run_gate({"audit": "fail"}, current, base),
            "changed-scope audit failure passes when no findings were introduced",
        )

        write_json(base / "test.json", {"success": False, "data": {"test_counts": {"failed": 2, "errors": 1}}})
        write_json(current / "test.json", {"success": False, "data": {"test_counts": {"failed": 2, "errors": 1}}})
        assert_equal(
            {"test": "no_comparable_evidence"},
            run_gate({"test": "fail"}, current, base),
            "aggregate test counts without outcome sidecars fail closed",
        )

        write_json(current / "test.json", {"success": False, "data": {"test_counts": {"failed": 3, "errors": 1}}})
        assert_equal(
            {"test": "no_comparable_evidence"},
            run_gate({"test": "fail"}, current, base),
            "aggregate test regressions require identity evidence",
        )

        write_json(
            current / "test.json",
            {
                "success": False,
                "data": {
                    "test_counts": {"failed": 3, "errors": 1},
                    "changed_since": {"introduced_failures": 0},
                },
            },
        )
        assert_equal(
            {"test": "no_comparable_evidence"},
            run_gate({"test": "fail"}, current, base),
            "changed-scope counts do not replace per-test outcomes",
        )

        assert_equal(
            {"lint": "inconclusive", "audit": "pass"},
            run_gate({"lint": "fail", "audit": "pass"}, current, base),
            "lint failure without comparable metrics is inconclusive",
        )

        (base / "audit.json").unlink()
        (current / "audit.json").unlink()
        assert_equal(
            {"audit": "inconclusive"},
            run_gate({"audit": "fail"}, current, base),
            "missing metric files are inconclusive",
        )

    print("All differential gate checks passed.")


if __name__ == "__main__":
    main()
