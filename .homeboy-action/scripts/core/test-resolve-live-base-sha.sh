#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESOLVER="${SCRIPT_DIR}/resolve-live-base-sha.sh"
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/homeboy-live-base.XXXXXX")"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/bin/gh" <<'SH'
#!/usr/bin/env bash
[ "$1" = api ]
[ "$2" = 'repos/example/project/commits/release%2Fnext' ]
printf '%s\n' '1111111111111111111111111111111111111111'
SH
chmod +x "${TMP_DIR}/bin/gh"

GITHUB_OUTPUT="${TMP_DIR}/pr-output" \
EVENT_NAME=pull_request \
REPOSITORY=example/project \
BASE_REF=release/next \
PATH="${TMP_DIR}/bin:${PATH}" \
  bash "${RESOLVER}"

grep -qx 'sha=1111111111111111111111111111111111111111' "${TMP_DIR}/pr-output"

GITHUB_OUTPUT="${TMP_DIR}/push-output" \
EVENT_NAME=push \
CURRENT_SHA=2222222222222222222222222222222222222222 \
  bash "${RESOLVER}"

grep -qx 'sha=2222222222222222222222222222222222222222' "${TMP_DIR}/push-output"

python3 - "${SCRIPT_DIR}/../../.github/workflows/ci.yml" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1], encoding="utf-8"))
jobs = workflow["jobs"]
plan = jobs["plan"]
assert plan["outputs"]["base-sha"] == "${{ steps.base-sha.outputs.sha }}"

baseline_checkout = jobs["baseline"]["steps"][0]
assert baseline_checkout["with"]["ref"] == "${{ needs.plan.outputs.base-sha }}"

for name in ("candidate", "baseline", "reconcile"):
    rendered = yaml.safe_dump(jobs[name])
    assert "github.event.pull_request.base.sha" not in rendered, name
    assert "needs.plan.outputs.base-sha" in rendered, name
PY

printf 'PASS: live base revision resolution\n'
