#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"

python3 - "${WORKFLOW}" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1]))
on_key = next(key for key, value in workflow.items() if isinstance(value, dict) and "workflow_call" in value)
inputs = workflow[on_key]["workflow_call"]["inputs"]
problems = []

if inputs.get("test-shards", {}).get("default") != "1":
    problems.append("test-shards must preserve the public unsharded default")
if inputs.get("allow-oversized-test-scope", {}).get("default") != "false":
    problems.append("oversized single-process execution must require an explicit false-default override")

plan_steps = workflow["jobs"]["plan"]["steps"]
plan = next((step for step in plan_steps if step.get("name") == "Plan quality checks"), None)
if plan is None or 'if [ "${TEST_SHARDS}" != \'1\' ] && [ -n "${TEST_SHARDS}" ]; then' not in plan.get("run", ""):
    problems.append("only explicit shard counts above one may enter inventory planning")

steps = workflow["jobs"]["candidate-test-plan"]["steps"]
outputs = workflow["jobs"]["candidate-test-plan"].get("outputs") or {}
for key in ["budget-verdict", "budget-max-estimate-ms", "budget-seconds", "budget-override"]:
    if f"${{{{ steps.plan.outputs.{key} }}}}" != outputs.get(key):
        problems.append(f"candidate Test plan must expose durable {key} output")
preflight = next((step for step in steps if step.get("id") == "plan"), None)
if preflight is None:
    problems.append("candidate Test planner is missing")
else:
    env = preflight.get("env") or {}
    expected = {
        "TEST_SCOPE_SHARDS": "${{ inputs.test-shards }}",
        "TEST_SCOPE_BUDGET_SECONDS": "${{ inputs.test-timeout-seconds }}",
        "TEST_SCOPE_ALLOW_OVERRIDE": "${{ inputs.allow-oversized-test-scope }}",
        "TEST_SCOPE_BUDGET_FILE": "${{ github.workspace }}/homeboy-test-budget.json",
    }
    for key, value in expected.items():
        if env.get(key) != value:
            problems.append(f"candidate Test planner must provide {key} to the preflight")
    run = preflight.get("run", "")
    if "preflight-test-scope-budget.sh" not in run or "TEST_SHARD_COUNT=" not in run:
        problems.append("candidate Test planner must route before creating a shard plan")
    if 'preflight-test-scope-budget.sh verify' not in run:
        problems.append("candidate Test planner must verify the shard plan against the budget")
    if 'shard-tests.sh attach-budget' not in run:
        problems.append("candidate Test planner must attach budget evidence before publishing the plan")

failure_step = next((step for step in steps if step.get("name") == "Write candidate Test inventory failure provenance"), None)
if failure_step is None:
    problems.append("candidate Test planner has no failure provenance step")
else:
    failure_run = failure_step.get("run", "")
    if 'if [ -f homeboy-test-budget.json ]; then' not in failure_run or 'cp homeboy-test-budget.json homeboy-test-inventory-failure/budget.json' not in failure_run:
        problems.append("failed Test planning must retain resolvable budget evidence in its provenance artifact")

upload_failure = next((step for step in steps if step.get("name") == "Upload candidate Test inventory failure provenance"), None)
if upload_failure is None or upload_failure.get("with", {}).get("path") != "homeboy-test-inventory-failure":
    problems.append("failure provenance artifact must upload the budget evidence directory")

policy = workflow["jobs"]["policy"].get("if", "")
if "needs.reconcile-test-shards.result == 'success'" not in policy:
    problems.append("PR policy must wait for inventory-routed Test reconciliation")
if "needs.plan.outputs.test-shards-enabled != 'true'" not in policy:
    problems.append("PR policy must run when no Test shard plan exists")

# Fixtures for the two independently reachable policy paths. The workflow
# expression above must preserve both: no Test command has no shard verdict,
# while an opted-in Test command must provide a successful shard verdict.
for name, shards_enabled, shard_result, expected in [
    ("no-test", "false", "skipped", True),
    ("sharded-pass", "true", "success", True),
    ("sharded-fail", "true", "failure", False),
]:
    actual = shards_enabled != "true" or shard_result == "success"
    if actual != expected:
        problems.append(f"PR policy fixture {name} has the wrong Test dependency verdict")

if problems:
    print("\n".join(problems))
    raise SystemExit(1)
PY

printf 'PASS: reusable workflow routes Test through inventory-bound scope-budget preflight\n'
