#!/usr/bin/env python3
"""Validate shard Test evidence pairs and emit one canonical aggregate pair."""

from __future__ import annotations

import hashlib
import json
import os
import sys
from pathlib import Path
from typing import Any


def fail(message: str) -> None:
    raise ValueError(message)


def read_json(path: str) -> dict[str, Any]:
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        fail(f"missing or malformed evidence file {path}: {error}")
    if not isinstance(value, dict):
        fail(f"evidence file is not an object: {path}")
    return value


def canonical_sha256(value: Any) -> str:
    encoded = json.dumps(value, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def is_sha256(value: Any) -> bool:
    return isinstance(value, str) and len(value) == 64 and all(char in "0123456789abcdef" for char in value)


def command_identity(command: str) -> str:
    parts = command.split()
    if parts[:2] == ["review", "test"]:
        return "review test"
    if parts[:1] == ["test"]:
        return "test"
    return command.strip()


def inventory_payload(command: str, provenance: dict[str, str], tests: list[str]) -> dict[str, Any]:
    return {
        "command": command,
        "execution_fingerprint": provenance["execution_fingerprint"],
        "runner": provenance["runner"],
        "runner_fingerprint": provenance["runner_fingerprint"],
        "schema": "homeboy/test-inventory/v1",
        "tests": [{"id": identity} for identity in sorted(tests)],
        "workspace_fingerprint": provenance["workspace_fingerprint"],
    }


def write_json(path: Path, value: dict[str, Any]) -> None:
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    os.replace(temporary, path)


def main() -> int:
    if len(sys.argv) != 5:
        print("usage: aggregate-test-evidence.py PLAN INVENTORY COMMAND OUTPUT_DIR", file=sys.stderr)
        return 2
    plan = read_json(sys.argv[1])
    parent = read_json(sys.argv[2])
    full_command = sys.argv[3]
    output = Path(sys.argv[4])
    evidence_command = command_identity(full_command)
    records = [json.loads(line) for line in sys.stdin if line.strip()]

    shards = plan.get("shards")
    parent_tests = parent.get("tests")
    if not isinstance(shards, list) or not isinstance(parent_tests, list):
        fail("plan or parent inventory is malformed")
    parent_ids = [test.get("id") for test in parent_tests if isinstance(test, dict)]
    if len(parent_ids) != len(parent_tests) or any(not isinstance(value, str) or not value for value in parent_ids):
        fail("parent inventory contains malformed identities")
    parent_provenance = {key: parent.get(key) for key in ("runner", "runner_fingerprint", "workspace_fingerprint")}
    if not isinstance(parent_provenance["runner"], str) or not parent_provenance["runner"]:
        fail("parent inventory runner is malformed")
    if any(not is_sha256(parent_provenance[key]) for key in ("runner_fingerprint", "workspace_fingerprint")):
        fail("parent inventory provenance is malformed")

    by_id = {record.get("id"): record for record in records if isinstance(record, dict)}
    shard_ids = [shard.get("id") for shard in shards if isinstance(shard, dict)]
    if len(by_id) != len(records) or sorted(by_id) != sorted(shard_ids):
        fail("shard evidence records are missing, duplicate, or unknown")

    aggregate_ids: list[str] = []
    aggregate_failed: list[str] = []
    executions: list[dict[str, str]] = []
    shared_fields = ("command", "runner", "runner_fingerprint", "workspace_fingerprint", "execution_fingerprint", "inventory_fingerprint")
    for shard in shards:
        shard_id = shard["id"]
        record = by_id[shard_id]
        tests = shard.get("tests")
        if not isinstance(tests, list) or not tests or any(not isinstance(value, str) or not value for value in tests) or len(set(tests)) != len(tests):
            fail(f"{shard_id} has malformed planned identities")
        if any(shard.get(key) != parent_provenance[key] for key in ("runner", "runner_fingerprint", "workspace_fingerprint")):
            fail(f"{shard_id} provenance differs from the parent inventory")
        source_fingerprint = shard.get("inventory_fingerprint")
        if not is_sha256(source_fingerprint):
            fail(f"{shard_id} source inventory fingerprint is malformed")
        execution = canonical_sha256({
            "inventory_fingerprint": source_fingerprint,
            "runner": shard["runner"],
            "runner_fingerprint": shard["runner_fingerprint"],
            "source": "shard_manifest",
            "source_id": shard_id,
            "tests": sorted(tests),
            "workspace_fingerprint": shard["workspace_fingerprint"],
        })
        inventory = read_json(record["inventory"])
        outcomes = read_json(record["outcomes"])
        if inventory.get("schema") != "homeboy/test-inventory/v1" or outcomes.get("schema") != "homeboy/test-outcomes/v1":
            fail(f"{shard_id} sidecar schemas are malformed")
        if any(outcomes.get(key) != inventory.get(key) for key in shared_fields):
            fail(f"{shard_id} sidecar pair has mismatched identity or provenance")
        expected_provenance = {**parent_provenance, "execution_fingerprint": execution}
        if inventory.get("command") != evidence_command or any(inventory.get(key) != value for key, value in expected_provenance.items()):
            fail(f"{shard_id} sidecar provenance does not match its immutable manifest")
        inventory_tests = inventory.get("tests")
        inventory_ids = [test.get("id") for test in inventory_tests if isinstance(test, dict)] if isinstance(inventory_tests, list) else []
        failed_ids = outcomes.get("failed_test_ids")
        if sorted(inventory_ids) != sorted(tests) or len(inventory_ids) != len(set(inventory_ids)):
            fail(f"{shard_id} inventory is not its exact planned projection")
        if not isinstance(failed_ids, list) or any(not isinstance(value, str) or not value for value in failed_ids) or len(failed_ids) != len(set(failed_ids)) or not set(failed_ids).issubset(inventory_ids):
            fail(f"{shard_id} outcomes contain malformed, duplicate, or unknown failures")
        expected_inventory = inventory_payload(evidence_command, expected_provenance, inventory_ids)
        if inventory.get("inventory_fingerprint") != canonical_sha256(expected_inventory):
            fail(f"{shard_id} inventory fingerprint is not canonical")
        status = record.get("status")
        failed_count = record.get("failed_count")
        if status == "pass" and failed_ids:
            fail(f"{shard_id} passed with observed failed identities")
        if status != "pass" and (not failed_ids or failed_count != len(failed_ids)):
            fail(f"{shard_id} failed without complete observed failure identities")
        aggregate_ids.extend(inventory_ids)
        aggregate_failed.extend(failed_ids)
        executions.append({"execution_fingerprint": execution, "id": shard_id})

    if len(aggregate_ids) != len(set(aggregate_ids)) or sorted(aggregate_ids) != sorted(parent_ids):
        fail("shard inventories do not form the exact duplicate-free parent inventory")
    if len(aggregate_failed) != len(set(aggregate_failed)):
        fail("failed identities are duplicated across shards")
    aggregate_execution = canonical_sha256({
        "plan_digest": plan.get("plan_digest"),
        "shards": sorted(executions, key=lambda value: value["id"]),
        "source": "shard_aggregate",
    })
    aggregate_provenance = {**parent_provenance, "execution_fingerprint": aggregate_execution}
    aggregate_inventory = inventory_payload(evidence_command, aggregate_provenance, aggregate_ids)
    aggregate_inventory["inventory_fingerprint"] = canonical_sha256(aggregate_inventory)
    aggregate_outcomes = {
        "schema": "homeboy/test-outcomes/v1",
        "command": evidence_command,
        **aggregate_provenance,
        "inventory_fingerprint": aggregate_inventory["inventory_fingerprint"],
        "failed_test_ids": sorted(aggregate_failed),
    }
    stem = "".join(char if char.isalnum() or char in "._-" else "-" for char in full_command).strip("-") or "homeboy-output"
    write_json(output / f"{stem}.test-inventory.json", aggregate_inventory)
    write_json(output / f"{stem}.test-outcomes.json", aggregate_outcomes)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
        print(f"::error::Invalid Test shard evidence: {error}", file=sys.stderr)
        raise SystemExit(1)
