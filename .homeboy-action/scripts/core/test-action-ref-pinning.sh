#!/usr/bin/env bash

# Every job must check out the action at ONE resolved SHA.
#
# Jobs used to check out `inputs.action-ref` independently. That default is a
# floating tag (v2) and jobs in a run are minutes apart, so a release landing
# mid-run gave different jobs different action code — and provenance
# reconciliation, which compares an action_revision derived from each job's own
# checkout, failed closed for a reason no operator would guess. See #332.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"
failed=0

check() {
  if [ "$1" -eq 0 ]; then printf 'PASS: %s\n' "$2"; else printf 'FAIL: %s\n' "$2"; failed=1; fi
}

python3 - "${WORKFLOW}" <<'PY'
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
jobs = d["jobs"]
problems = []

pinned = "${{ needs.plan.outputs.action-sha }}"
for name, job in jobs.items():
    refs = [
        (s.get("with") or {}).get("ref")
        for s in job.get("steps", [])
        if (s.get("with") or {}).get("path") == ".homeboy-action"
    ]
    if not refs:
        continue
    expected = "${{ steps.action-sha.outputs.sha }}" if name == "plan" else pinned
    for r in refs:
        if r != expected:
            problems.append(f"{name}: checks out .homeboy-action at {r!r}, not the resolved SHA")
    needs = job.get("needs")
    needs = [needs] if isinstance(needs, str) else (needs or [])
    # needs only exposes DIRECT dependencies, so a transitive path to plan is
    # not enough to read needs.plan.outputs.
    if name != "plan" and "plan" not in needs:
        problems.append(f"{name}: consumes needs.plan.outputs but does not list plan in needs")

if jobs.get("plan", {}).get("outputs", {}).get("action-sha") is None:
    problems.append("plan does not publish an action-sha output")

if problems:
    print("\n".join(problems))
    sys.exit(1)
PY
check $? "every action checkout is pinned to the plan-resolved SHA and needs plan"

# The resolver must prefer the ^{} dereferenced commit. An annotated tag
# resolves to a TAG OBJECT, and `git ls-remote` sorts its output rather than
# honouring argument order — so taking the first line silently yields the tag
# object while `rev-parse HEAD` in the consuming job reports the commit. That
# is the same mismatch this pinning removes.
grep -q 'awk .\$2 ~ /\\\^\\{\\}\$/' "${WORKFLOW}"
check $? "resolver prefers the dereferenced commit over the annotated tag object"

grep -q 'ACTION_REF.*=~ \^\[0-9a-f\]{40}\$' "${WORKFLOW}"
check $? "a full SHA passes through without a network lookup"

grep -q 'Could not resolve action-ref' "${WORKFLOW}"
check $? "an unresolvable ref fails loudly instead of checking out nothing"

[ "${failed}" -eq 0 ] || exit 1
printf 'Action ref pinning checks passed.\n'
