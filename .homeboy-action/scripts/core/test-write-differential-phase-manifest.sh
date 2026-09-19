#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WRITER="${ROOT}/scripts/core/write-differential-phase-manifest.sh"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

run_writer() {
  PHASE=candidate \
  PHASE_ARTIFACT_NAME=homeboy-differential-candidate-fixture-2 \
  PHASE_OUTPUT_DIR="${tmp}/candidate" \
  REPOSITORY=example/repo \
  GITHUB_RUN_ID=42 \
  CANDIDATE_SHA=candidate \
  BASE_SHA=base \
  CHECKOUT_SHA=candidate \
  COMMAND='review test' \
  COMPONENT=component \
  ACTION_REVISION=action-sha \
  CLI_REVISION="${CLI_REVISION-}" \
  BINARY_SHA256=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
  EXECUTION_STATE="${EXECUTION_STATE:-completed}" \
  RUN_ATTEMPT=2 \
  RESULTS="${RESULTS:-{\"review test\":\"pass\"}}" \
    bash "${WRITER}"
}

CLI_REVISION='homeboy 9.9.9+candidate' run_writer >/dev/null
jq -e '.cli_revision == "homeboy 9.9.9+candidate" and .results["review test"] == "pass"' \
  "${tmp}/candidate/manifest.json" >/dev/null
printf 'PASS: producer atomically publishes a manifest with selected CLI identity\n'

rm -rf "${tmp}/candidate"
CLI_REVISION='homeboy 9.9.9+candidate' EXECUTION_STATE=pr_closed RESULTS='{"review test":"fail"}' run_writer >/dev/null
jq -e '.execution_state == "pr_closed" and .results == {}' "${tmp}/candidate/manifest.json" >/dev/null
printf 'PASS: producer records pr_closed without synthetic quality results\n'

rm -rf "${tmp}/candidate"
CLI_REVISION='homeboy 9.9.9+candidate' EXECUTION_STATE=pr_merged RESULTS='{"review test":"fail"}' run_writer >/dev/null
jq -e '.execution_state == "pr_merged" and .results == {}' "${tmp}/candidate/manifest.json" >/dev/null
printf 'PASS: producer records pr_merged without synthetic quality results\n'

rm -rf "${tmp}/candidate"
set +e
output="$(CLI_REVISION='homeboy 9.9.9+candidate' EXECUTION_STATE=unknown run_writer 2>&1)"
status=$?
set -e
if [ "${status}" -eq 0 ] || [ -e "${tmp}/candidate/manifest.json" ]; then
  printf 'FAIL: unknown execution state was published\n%s\n' "${output}"
  exit 1
fi
case "${output}" in
  *"field 'execution_state' must be 'completed', 'pr_closed', or 'pr_merged'"*) ;;
  *) printf 'FAIL: unknown execution state omitted its contract error\n%s\n' "${output}"; exit 1 ;;
esac
printf 'PASS: producer rejects unknown execution states\n'

rm -rf "${tmp}/candidate"
set +e
output="$(CLI_REVISION='' run_writer 2>&1)"
status=$?
set -e
if [ "${status}" -eq 0 ] || [ -e "${tmp}/candidate/manifest.json" ]; then
  printf 'FAIL: empty CLI provenance was published\n%s\n' "${output}"
  exit 1
fi
for evidence in \
  "candidate differential provenance artifact 'homeboy-differential-candidate-fixture-2'" \
  "field 'cli_revision' must be a non-empty string" \
  'gh run rerun 42 --repo example/repo'; do
  case "${output}" in
    *"${evidence}"*) ;;
    *) printf 'FAIL: publication error omitted %s\n%s\n' "${evidence}" "${output}"; exit 1 ;;
  esac
done
printf 'PASS: malformed producer provenance fails before publication with repair evidence\n'

# The selected binary owns CLI identity even when the composite phase exits
# before its optional tooling-metadata step can expose outputs.
# shellcheck disable=SC2016
[ "$(grep -Fc 'CLI_REVISION: ${{ steps.candidate-binary.outputs.cli-revision }}' "${WORKFLOW}")" -eq 2 ] \
  || { printf 'FAIL: candidate and baseline writers do not use selected binary identity\n'; exit 1; }
[ "$(grep -Fc 'bash .homeboy-action/scripts/core/write-differential-phase-manifest.sh' "${WORKFLOW}")" -eq 2 ] \
  || { printf 'FAIL: differential producers bypass manifest validation\n'; exit 1; }
grep -F "if: always() && steps.provenance.outcome == 'success'" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: candidate publication is not gated by provenance validation\n'; exit 1; }
grep -F "if: always() && steps.baseline-policy.outputs.run == 'true' && steps.provenance.outcome == 'success'" "${WORKFLOW}" >/dev/null \
  || { printf 'FAIL: baseline publication is not gated by provenance validation\n'; exit 1; }
printf 'PASS: every differential publication is gated by the owning producer contract\n'
