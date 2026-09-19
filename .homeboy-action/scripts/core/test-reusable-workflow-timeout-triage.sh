#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"

assert_contains() {
  local needle="$1" label="$2"
  if ! grep -Fq -- "${needle}" "${WORKFLOW}"; then
    printf 'FAIL: %s\nmissing: %s\n' "${label}" "${needle}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

assert_contains 'cp -R homeboy-observations differential-phase/candidate/homeboy-observations' 'candidate provenance retains observation evidence'
assert_contains 'HOMEBOY_CI_RESULTS_ARTIFACT: homeboy-differential-candidate-${{ matrix.artifact_key }}-${{ github.run_attempt }}' 'reconciled comment identifies the result artifact'
assert_contains 'HOMEBOY_OBSERVATIONS_ARTIFACT: homeboy-differential-candidate-${{ matrix.artifact_key }}-${{ github.run_attempt }}' 'reconciled comment identifies the observation artifact'
assert_contains 'GITHUB_PR_URL: ${{ github.event.pull_request.html_url }}' 'reconciled comment receives a resolvable PR URL'
assert_contains 'HOMEBOY_TEST_TIMEOUT_SECONDS: ${{ inputs.test-timeout-seconds }}' 'reconciled comment receives the test timeout budget'
assert_contains 'HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS: ${{ inputs.execution-timeout-seconds }}' 'reconciled comment receives the execution timeout budget'
assert_contains 'GITHUB_RUN_URL: ${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}' 'reconciled comment links the current workflow run'

printf 'Reusable workflow timeout triage contract checks passed.\n'
