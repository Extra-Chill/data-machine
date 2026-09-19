#!/usr/bin/env bash

# Unsharded candidate and baseline phases retain Homeboy's existing cap guard;
# opted-in shard planning additionally receives the cap for per-process math.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT_DIR}/.github/workflows/ci.yml"

python3 - "${WORKFLOW}" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1]))
problems = []

on_key = next(k for k in workflow if isinstance(workflow[k], dict) and "workflow_call" in workflow[k])
inputs = workflow[on_key]["workflow_call"]["inputs"]

cap = inputs.get("max-changed-test-files")
if cap is None:
    problems.append("missing workflow_call input max-changed-test-files")
elif cap.get("type") != "string":
    problems.append("max-changed-test-files must be a string input")
elif cap.get("default") != "":
    problems.append("max-changed-test-files must default to '' (disabled, behaviour unchanged)")

def phase_step(job_name, step_name):
    for step in workflow["jobs"][job_name].get("steps", []):
        if step.get("name") == step_name:
            return step
    return None

expected = "${{ inputs.max-changed-test-files }}"
for job_name, step_name in [
    ("candidate", "Run candidate ${{ matrix.title }}"),
    ("baseline", "Run baseline ${{ matrix.title }}"),
]:
    step = phase_step(job_name, step_name)
    if step is None:
        problems.append(f"{job_name} has no {step_name!r} step")
    elif (step.get("env") or {}).get("HOMEBOY_MAX_CHANGED_TEST_FILES") != expected:
        problems.append(f"{job_name} must forward max-changed-test-files to Homeboy")

for job_name, step_name in [
    ("candidate-test-plan", "Configure candidate Test shards"),
    ("candidate-test-shards", "Run candidate Test shard ${{ matrix.shard_id }}"),
]:
    step = phase_step(job_name, step_name)
    if step is not None and "HOMEBOY_MAX_CHANGED_TEST_FILES" in (step.get("env") or {}):
        problems.append(f"{job_name} must not apply the selection cap after inventory routing")

if problems:
    print("\n".join(problems))
    sys.exit(1)
PY

if [ "$(grep -Fc 'HOMEBOY_MAX_CHANGED_TEST_FILES: ${{ inputs.max-changed-test-files }}' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: candidate and baseline phases must forward max-changed-test-files exactly twice\n'
  exit 1
fi
if grep -Fq 'TEST_SCOPE_CAP:' "${WORKFLOW}"; then
  printf 'FAIL: generic Test inventories must not be treated as changed-file selections\n'
  exit 1
fi

printf 'PASS: reusable workflow preserves Homeboy as the authoritative file-cap enforcer\n'
