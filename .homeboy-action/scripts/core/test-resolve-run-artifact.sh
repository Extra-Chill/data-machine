#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/bin"

cat > "${tmp}/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' \
  homeboy-test-archive-1 \
  homeboy-test-archive-3 \
  homeboy-test-shard-plan-2 \
  homeboy-candidate-test-results-1 \
  unrelated
SH
chmod +x "${tmp}/bin/gh"

resolve() {
  : > "${tmp}/output"
  PATH="${tmp}/bin:${PATH}" GITHUB_REPOSITORY=example/repo GITHUB_RUN_ID=42 GITHUB_RUN_ATTEMPT="$1" GITHUB_OUTPUT="${tmp}/output" \
    bash "${ROOT}/scripts/core/resolve-run-artifact.sh" "$2" >/dev/null
}

resolve 2 homeboy-test-archive
grep -Fx 'artifact-name=homeboy-test-archive-1' "${tmp}/output" >/dev/null || { printf 'FAIL: prior producer attempt was not selected\n'; exit 1; }
resolve 3 homeboy-test-archive
grep -Fx 'artifact-name=homeboy-test-archive-3' "${tmp}/output" >/dev/null || { printf 'FAIL: newest available producer attempt was not selected\n'; exit 1; }
resolve 2 homeboy-candidate-test-results
grep -Fx 'artifact-name=homeboy-candidate-test-results-1' "${tmp}/output" >/dev/null || { printf 'FAIL: final Test reconciliation did not reuse the prior aggregate result\n'; exit 1; }
if resolve 1 homeboy-test-shard-plan >/dev/null 2>&1; then
  printf 'FAIL: resolver accepted an artifact from a future attempt\n'; exit 1
fi

cat > "${tmp}/bin/gh" <<'SH'
#!/usr/bin/env bash
printf '%s\n' homeboy-test-archive-2 homeboy-test-archive-2
SH
chmod +x "${tmp}/bin/gh"
if resolve 2 homeboy-test-archive >/dev/null 2>&1; then
  printf 'FAIL: resolver accepted duplicate highest-attempt artifacts\n'; exit 1
fi
printf 'PASS: run artifact resolution reuses only one newest available producer attempt\n'
