#!/usr/bin/env bash

# A composite action cannot expose its internal steps through the GitHub Jobs
# API. The reusable workflow must therefore name every action invocation that
# owns a user-visible phase, including failing command and shard executions.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT_DIR}/.github/workflows/ci.yml"

python3 - "${WORKFLOW}" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1]))
problems = []

expected = {
    "binary": ["Binary setup"],
    "candidate": ["Run candidate ${{ matrix.title }}"],
    "baseline": ["Run baseline ${{ matrix.title }}"],
    "candidate-test-plan": ["Configure candidate Test shards"],
    "candidate-test-shards": ["Run candidate Test shard ${{ matrix.shard_id }}"],
}

for job_name, names in expected.items():
    actual = [step.get("name") for step in workflow["jobs"][job_name].get("steps", [])]
    for name in names:
        if name not in actual:
            problems.append(f"{job_name} does not expose named phase {name!r}")

for job_name, job in workflow["jobs"].items():
    for step in job.get("steps", []):
        if step.get("uses") == "./.homeboy-action" and not step.get("name"):
            problems.append(f"{job_name} has an anonymous Homeboy Action invocation")

if problems:
    print("\n".join(problems))
    sys.exit(1)
PY

printf 'PASS: reusable workflow exposes named GitHub-native Homeboy phases\n'
