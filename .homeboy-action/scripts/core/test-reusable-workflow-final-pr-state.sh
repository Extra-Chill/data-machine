#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT_DIR}/.github/workflows/ci.yml"
ACTION="${ROOT_DIR}/action.yml"
PROBE="${ROOT_DIR}/scripts/core/check-pr-state.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/bin/gh" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "${PR_STATE}"
SH
chmod +x "${TMP_DIR}/bin/gh"

assert_pr_state() {
  local observation="$1" expected_active="$2" expected_reason="$3" expected_state="$4" label="$5"
  : > "${TMP_DIR}/output"
  PR_STATE="${observation}" PR_NUMBER=419 GITHUB_REPOSITORY=Extra-Chill/homeboy-action \
    GITHUB_OUTPUT="${TMP_DIR}/output" PATH="${TMP_DIR}/bin:${PATH}" bash "${PROBE}" >/dev/null
  if ! grep -Fxq "active=${expected_active}" "${TMP_DIR}/output"; then
    printf 'FAIL: %s did not report active=%s\n' "${label}" "${expected_active}"
    exit 1
  fi
  if ! grep -Fxq "reason=${expected_reason}" "${TMP_DIR}/output"; then
    printf 'FAIL: %s did not report reason=%s\n' "${label}" "${expected_reason}"
    exit 1
  fi
  grep -Fxq "state=${expected_state}" "${TMP_DIR}/output" \
    || { printf 'FAIL: %s did not report state=%s\n' "${label}" "${expected_state}"; exit 1; }
  printf 'PASS: %s\n' "${label}"
}

# A closure can arrive while any candidate phase is completing; the finalizer
# must suppress its synthetic verdict for both closed and merged PRs.
assert_pr_state CLOSED false pr_closed closed 'closed candidate suppresses finalization'
assert_pr_state $'CLOSED\t2026-08-31T19:00:00Z' false pr_merged merged 'merged candidate suppresses finalization'
assert_pr_state OPEN true active open 'active candidate remains eligible for finalization'

python3 - "${ACTION}" <<'PY'
import sys
import yaml

action = yaml.safe_load(open(sys.argv[1]))
probes = [step for step in action["runs"]["steps"] if step.get("id") in ("check-pr-state", "final-pr-state")]
problems = []
if action.get("outputs", {}).get("pr-state-reason", {}).get("value") != "${{ steps.check-pr-state.outputs.reason }}":
    problems.append("composite action does not expose its pre-execution PR lifecycle state")
if action.get("outputs", {}).get("pr-state", {}).get("value") != "${{ steps.check-pr-state.outputs.state }}":
    problems.append("composite action does not expose its typed pre-execution PR lifecycle state")
if len(probes) != 2:
    problems.append("composite action must have initial and final PR-state probes")
for probe in probes:
    if probe.get("env", {}).get("GH_TOKEN") != "${{ inputs.app-token || github.token }}":
        problems.append(f"{probe.get('id', 'unknown')} lacks an authenticated GitHub state lookup")
if problems:
    print("\n".join(problems))
    sys.exit(1)
PY
printf 'PASS: composite PR-state probes authenticate lifecycle lookups\n'

python3 - "${WORKFLOW}" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1]))
problems = []
for job_name in ("reconcile", "reconcile-test-shards"):
    steps = workflow["jobs"][job_name]["steps"]
    probe = next((i for i, step in enumerate(steps) if step.get("name") == "Check final PR state"), None)
    if probe is None:
        problems.append(f"{job_name} does not re-check PR state before finalization")
        continue
    if steps[probe].get("id") != "final-pr-state":
        problems.append(f"{job_name} final PR state probe lacks its output id")
    if "always()" not in steps[probe].get("if", ""):
        problems.append(f"{job_name} final PR state probe does not run after cancelled dependencies")
    if steps[probe].get("env", {}).get("GH_TOKEN") != "${{ github.token }}":
        problems.append(f"{job_name} final PR state probe lacks an authenticated GitHub state lookup")
    for step in steps[probe + 1:]:
        condition = step.get("if", "")
        name = step.get("name", step.get("uses", "unnamed step"))
        if name in ("Generate GitHub App token", "Post reconciled PR comment"):
            if "steps.final-pr-state.outputs.active != 'false'" not in condition:
                problems.append(f"{job_name} PR comment can run after PR closure")
        elif "steps.final-pr-state.outputs.active != 'false'" in condition:
            problems.append(f"{job_name} immutable evidence step {name!r} is skipped after PR closure")
    if job_name == "reconcile":
        reconcile = next((step for step in steps if step.get("name") == "Reconcile provenance-bound phase evidence"), {})
        if reconcile.get("env", {}).get("PR_ACTIVE") != "${{ steps.final-pr-state.outputs.active }}":
            problems.append("differential reconciliation lacks the final PR lifecycle state")
        if reconcile.get("env", {}).get("PR_STATE") != "${{ steps.final-pr-state.outputs.state }}":
            problems.append("differential reconciliation lacks the typed final PR lifecycle state")

if problems:
    print("\n".join(problems))
    sys.exit(1)
PY

# `always()` is intentional: immutable artifacts must reconcile even if the PR
# closes while jobs are completing. Active and closed PRs both fail closed when
# the evidence is missing or malformed; only PR publication is skipped.
grep -F "if: \${{ !inputs.contract-probe && always() && needs.plan.outputs.non-test-commands-enabled == 'true' }}" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: active non-Test cancellation can bypass reconciliation\n'; exit 1; }
grep -F "if: \${{ !inputs.contract-probe && always() && needs.plan.outputs.test-shards-enabled == 'true' }}" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: active sharded Test cancellation can bypass reconciliation\n'; exit 1; }
grep -F "if: needs.candidate-test-plan.result != 'success'" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: closed-PR Test inventory failure is not terminal\n'; exit 1; }
grep -F "if: needs.candidate-test-plan.result == 'success' && needs.candidate-test-result.result != 'success'" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: closed-PR Test aggregation failure is not terminal\n'; exit 1; }

printf 'PASS: reusable finalizers reconcile immutable evidence after PR closure and skip publication\n'
