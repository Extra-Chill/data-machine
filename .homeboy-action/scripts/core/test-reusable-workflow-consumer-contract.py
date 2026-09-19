#!/usr/bin/env python3

"""Validate the minimal caller contract for the published reusable workflow.

GitHub does not execute nested reusable workflows locally. This checks the
caller/callee semantics GitHub evaluates before scheduling any jobs, including
the permission rule that caused the #327/#328 startup failure.
"""

from pathlib import Path
import json
import os
import re
import subprocess
import sys
import tempfile

import yaml


ROOT = Path(__file__).resolve().parents[2]
CALLEE_PATH = ROOT / ".github/workflows/ci.yml"
CALLER_PATH = ROOT / "fixtures/reusable-workflow-minimal-consumer.yml"
NEGATIVE_CALLEE_PATH = ROOT / "fixtures/reusable-workflow-unavailable-permission-callee.yml"
PROBE_PATH = ROOT / ".github/workflows/consumer-contract.yml"
README_PATH = ROOT / "README.md"
EXPECTED_JOBS = {
    "plan": "Plan",
    "binary": "Prepare candidate Homeboy binary",
    "candidate": "Candidate ${{ matrix.title }}",
    "reconcile": "${{ matrix.title }}",
}
NORMAL_JOBS = {
    "binary",
    "plan",
    "candidate",
    "baseline",
    "candidate-test-plan",
    "candidate-test-shards",
    "candidate-test-result",
    "reconcile-test-shards",
    "reconcile",
    "policy",
}
NON_TEST_MATRIX_JOBS = {"candidate", "baseline", "reconcile"}
PERMISSION_LEVELS = {"none": 0, "read": 1, "write": 2}


def load_workflow(path):
    with path.open() as workflow:
        return yaml.safe_load(workflow)


def fail(message):
    print(f"FAIL: {message}")
    sys.exit(1)


callee = load_workflow(CALLEE_PATH)
caller = load_workflow(CALLER_PATH)
negative_callee = load_workflow(NEGATIVE_CALLEE_PATH)
probe = load_workflow(PROBE_PATH)

if "workflow_call" not in callee.get(True, callee.get("on", {})):
    fail("ci.yml is not callable with workflow_call")

if "fixtures/reusable-workflow-minimal-consumer.yml" not in README_PATH.read_text():
    fail("README does not document the canonical minimal reusable-workflow caller")

jobs = caller.get("jobs", {})
if set(jobs) != {"homeboy"}:
    fail("minimal caller must contain exactly the homeboy reusable-workflow job")

caller_job = jobs["homeboy"]
if caller_job.get("uses") != "Extra-Chill/homeboy-action/.github/workflows/ci.yml@v2":
    fail("minimal caller must use the published v2 reusable workflow")
if "runs-on" in caller_job:
    fail("reusable-workflow caller job must not declare runs-on")
if caller_job.get("secrets") != "inherit":
    fail("minimal caller must inherit caller secrets")

inputs = callee[True if True in callee else "on"]["workflow_call"].get("inputs", {})
if inputs.get("contract-probe", {}).get("type") != "boolean":
    fail("contract-probe must remain a boolean workflow_call input")
for input_name, value in caller_job.get("with", {}).items():
    if input_name not in inputs:
        fail(f"caller passes undocumented workflow_call input {input_name!r}")
    if inputs[input_name].get("type") != "string" or not isinstance(value, str):
        fail(f"caller input {input_name!r} is not compatible with its string workflow_call contract")

for input_name, definition in inputs.items():
    if definition.get("required") is True and input_name not in caller_job.get("with", {}):
        fail(f"minimal caller omits required workflow_call input {input_name!r}")

caller_permissions = caller.get("permissions", {})
callee_permissions = callee.get("permissions", {})


def assert_permission_contract(required_permissions, label, expected_to_pass):
    insufficient = []
    for scope, required_level in required_permissions.items():
        granted_level = caller_permissions.get(scope, "none")
        if required_level not in PERMISSION_LEVELS or granted_level not in PERMISSION_LEVELS:
            fail(f"unsupported permission level for {scope!r}")
        if PERMISSION_LEVELS[granted_level] < PERMISSION_LEVELS[required_level]:
            insufficient.append(f"{scope}: {granted_level} < {required_level}")

    if expected_to_pass and insufficient:
        fail(f"caller cannot start {label}: {', '.join(insufficient)}")
    if not expected_to_pass and not insufficient:
        fail(f"negative {label} unexpectedly permits the minimal caller")


assert_permission_contract(callee_permissions, "ci.yml", True)
assert_permission_contract(negative_callee.get("permissions", {}), "unavailable-permission fixture", False)

missing_jobs = set(EXPECTED_JOBS) - set(callee.get("jobs", {}))
if missing_jobs:
    fail(f"ci.yml no longer exposes required consumer jobs: {', '.join(sorted(missing_jobs))}")

for job_name, expected_name in EXPECTED_JOBS.items():
    if callee["jobs"][job_name].get("name") != expected_name:
        fail(f"ci.yml job {job_name!r} no longer exposes {expected_name!r} to callers")

probe_job = probe.get("jobs", {}).get("homeboy", {})
if probe_job.get("uses") != "./.github/workflows/ci.yml":
    fail("GitHub consumer probe does not invoke the promotion-candidate workflow")
if probe_job.get("with", {}).get("contract-probe") is not True:
    fail("GitHub consumer probe does not use bounded contract-probe mode")
if probe_job.get("secrets") != "inherit" or "runs-on" in probe_job:
    fail("GitHub consumer probe is not a valid reusable-workflow caller job")

contract_job = callee.get("jobs", {}).get("contract-probe", {})
if contract_job.get("name") != "Consumer contract" or contract_job.get("if") != "inputs.contract-probe":
    fail("ci.yml does not expose the bounded Consumer contract job")

for job_name in NORMAL_JOBS:
    condition = callee["jobs"].get(job_name, {}).get("if", "")
    if "inputs.contract-probe" not in condition:
        fail(f"normal job {job_name!r} can run during the bounded contract probe")

plan_script = callee["jobs"]["plan"]["steps"][-1]["run"]
plan_outputs = callee["jobs"]["plan"].get("outputs", {})
if plan_outputs.get("non-test-commands-enabled") != "${{ steps.plan.outputs.non-test-commands-enabled }}":
    fail("plan does not expose its non-test command decision")

for job_name in NON_TEST_MATRIX_JOBS:
    job = callee["jobs"][job_name]
    strategy_matrix = job.get("strategy", {}).get("matrix", "")
    if "fromJson(needs.plan.outputs.matrix)" not in strategy_matrix:
        fail(f"{job_name} no longer consumes the generic planned matrix")
    condition = job.get("if", "")
    if "needs.plan.outputs.non-test-commands-enabled == 'true'" not in condition:
        fail(f"{job_name} is not guarded by the planner's non-test command decision")

for job_name, job in callee["jobs"].items():
    if re.search(r"\bmatrix\.", str(job.get("if", ""))):
        fail(f"job-level if for {job_name!r} references matrix before strategy expansion")


def run_plan(commands, test_shards):
    with tempfile.TemporaryDirectory() as temporary_directory:
        output_path = Path(temporary_directory) / "github-output"
        environment = os.environ.copy()
        environment.update(
            {
                "COMMANDS": commands,
                "BASELINE_COMMANDS": "auto",
                "TEST_SHARDS": test_shards,
                "GITHUB_OUTPUT": str(output_path),
            }
        )
        result = subprocess.run(
            ["bash", "-e", "-o", "pipefail", "-c", plan_script],
            cwd=ROOT,
            env=environment,
            capture_output=True,
            text=True,
        )
        if result.returncode != 0:
            fail(f"planner execution failed: {result.stderr.strip()}")
        return dict(line.split("=", 1) for line in output_path.read_text().splitlines())


test_only_plan = run_plan("review test", "2")
if test_only_plan.get("test-shards-enabled") != "true":
    fail("test-only sharded planning does not enable the shard path")
if test_only_plan.get("non-test-commands-enabled") != "false":
    fail("test-only sharded planning does not disable non-test matrix jobs")
if json.loads(test_only_plan["matrix"]) != {"include": []}:
    fail("test-only sharded planning retains synthetic non-test matrix rows")

mixed_plan = run_plan("review lint,review test", "2")
if mixed_plan.get("test-shards-enabled") != "true":
    fail("mixed quality and Test planning does not enable the shard path")
if mixed_plan.get("non-test-commands-enabled") != "true":
    fail("mixed quality and Test planning disables non-test matrix jobs")
mixed_rows = json.loads(mixed_plan["matrix"])["include"]
if [row["section_key"] for row in mixed_rows] != ["lint"]:
    fail("mixed quality and Test planning does not preserve its quality matrix")

enforcement = callee["jobs"]["reconcile-test-shards"]
enforcement_steps = {step.get("name"): step for step in enforcement["steps"] if "name" in step}
aggregate_download = enforcement_steps.get("Resolve candidate Test result", {})
if "resolve-run-artifact.sh homeboy-candidate-test-results" not in aggregate_download.get("run", ""):
    fail("sharded Test reconciliation does not resolve a prior successful aggregate")
if "needs.candidate-test-result.result == 'success'" not in aggregate_download.get("if", ""):
    fail("sharded Test aggregate resolution is not gated on a successful aggregate")
if "steps.final-pr-state.outputs.active != 'false'" in aggregate_download.get("if", ""):
    fail("sharded Test aggregate resolution skips immutable evidence after PR closure")
aggregation_failure = enforcement_steps.get("Report candidate Test aggregation failure", {})
if "needs.candidate-test-result.result != 'success'" not in aggregation_failure.get("if", ""):
    fail("failed sharded Test aggregation is not routed to a red verdict")
if "exit 1" not in aggregation_failure.get("run", ""):
    fail("failed sharded Test aggregation does not fail the Test job")
aggregate_enforcement = enforcement_steps.get("Enforce sharded Test result", {})
if "needs.candidate-test-result.result == 'success'" not in aggregate_enforcement.get("if", ""):
    fail("successful sharded Test aggregation is not routed to final enforcement")
if "steps.final-pr-state.outputs.active != 'false'" in aggregate_enforcement.get("if", ""):
    fail("successful sharded Test aggregation skips immutable evidence after PR closure")
if "candidate-test-results/homeboy-ci-results/results.json" not in aggregate_enforcement.get("run", ""):
    fail("sharded Test enforcement does not consume the aggregate result")
if "baseline-command" in str(aggregate_enforcement):
    fail("sharded Test enforcement still has a baseline-command bypass")

print("PASS: minimal documented caller satisfies reusable workflow permissions and inputs")
print("PASS: reusable workflow exposes Plan, candidate preparation, candidate, and verdict jobs")
print("PASS: unavailable permission fixture is rejected before job scheduling")
print("PASS: GitHub consumer probe invokes the promotion candidate in bounded mode")
print("PASS: planner guards empty sharded-Test matrices without job-level matrix references")
