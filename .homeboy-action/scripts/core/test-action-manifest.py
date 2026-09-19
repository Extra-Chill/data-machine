#!/usr/bin/env python3

import copy
import re
from pathlib import Path

import yaml


ROOT = Path(__file__).resolve().parents[2]
ACTION = ROOT / "action.yml"
STATUS_FUNCTION = re.compile(r"\b(always|cancelled|failure|success)\s*\(")


def validate_composite_manifest(document: object) -> list[str]:
    if not isinstance(document, dict):
        return ["action.yml must contain a mapping"]

    runs = document.get("runs")
    if not isinstance(runs, dict) or runs.get("using") != "composite":
        return ["action.yml must define a composite action"]

    steps = runs.get("steps")
    if not isinstance(steps, list):
        return ["runs.steps must contain a sequence"]

    errors = []
    for index, step in enumerate(steps, start=1):
        if not isinstance(step, dict):
            errors.append(f"step {index} must contain a mapping")
            continue

        # GitHub runner action_yaml.json registers the four status functions
        # only for step-if. Other composite step fields use the narrower
        # string/boolean/with/env contexts and reject these functions at load.
        for field, value in step.items():
            if field == "if":
                continue
            values = value.values() if isinstance(value, dict) else (value,)
            for candidate in values:
                if not isinstance(candidate, str):
                    continue
                match = STATUS_FUNCTION.search(candidate)
                if match:
                    errors.append(
                        f"step {index} {field} uses {match.group(1)}(), "
                        "which GitHub permits only in composite step if"
                    )

    return errors


manifest = yaml.safe_load(ACTION.read_text(encoding="utf-8"))
errors = validate_composite_manifest(manifest)
if errors:
    raise SystemExit("FAIL: " + "\nFAIL: ".join(errors))

# Prove this parser rejects the exact regression rather than merely accepting
# the current file. The published failure placed cancelled() in a step env.
regression = copy.deepcopy(manifest)
regression["runs"]["steps"][0].setdefault("env", {})["HOMEBOY_RUN_CANCELLED"] = "${{ cancelled() }}"
regression_errors = validate_composite_manifest(regression)
if not any("env uses cancelled()" in error for error in regression_errors):
    raise SystemExit("FAIL: manifest validator accepted cancelled() in composite step env")

print("PASS: published composite manifest uses status functions only in supported step conditions")
