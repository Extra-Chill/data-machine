#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RECONCILE="${ROOT}/scripts/core/reconcile-differential-phases.sh"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

write_phase() {
  local phase="$1" checkout_sha="$2" component="$3" cli="$4" results="$5" payload="$6"
  local dir="${tmp}/artifacts/${phase}"
  mkdir -p "${dir}/homeboy-ci-results"
  printf '%s\n' "${payload}" > "${dir}/homeboy-ci-results/review-test.json"
  if printf '%s' "${results}" | jq -e '."review test" == "timeout"' >/dev/null; then
    jq -cn --arg phase "${phase}" --arg repository example/repo --arg candidate_sha candidate --arg base_sha base --arg checkout_sha "${checkout_sha}" --arg command 'review test' --arg component "${component}" --arg action_revision action-sha --arg cli_revision "${cli}" --arg binary_sha256 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' --argjson run_attempt 2 --argjson results "${results}" '{phase:$phase,repository:$repository,candidate_sha:$candidate_sha,base_sha:$base_sha,checkout_sha:$checkout_sha,command:$command,component:$component,action_revision:$action_revision,cli_revision:$cli_revision,binary_sha256:$binary_sha256,run_attempt:$run_attempt,results:$results}' > "${dir}/manifest.json"
    return
  fi
  outcome=passed
  printf '%s' "${results}" | jq -e '."review test" == "fail"' >/dev/null && outcome=failed
  execution_fingerprint=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
  inventory_fingerprint=9dbf5642740c6245489c5fa9cd655f95d5592393289bd250a4689dbe72e43333
  if [ "${phase}" = baseline ]; then
    execution_fingerprint=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
    inventory_fingerprint=b8281a5f59c43e3363b01fd17084972db2178833a4626a7088ee1ea4911369f3
  fi
  jq -cn --arg command 'review test' --arg outcome "${outcome}" --arg execution_fingerprint "${execution_fingerprint}" --arg inventory_fingerprint "${inventory_fingerprint}" '{schema:"homeboy/test-outcomes/v1",command:$command,runner:"nextest",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),execution_fingerprint:$execution_fingerprint,inventory_fingerprint:$inventory_fingerprint,failed_test_ids:(if $outcome == "failed" then ["stable"] else [] end)}' > "${dir}/homeboy-ci-results/review-test.test-outcomes.json"
  jq -cn --arg command 'review test' --arg execution_fingerprint "${execution_fingerprint}" --arg inventory_fingerprint "${inventory_fingerprint}" '{schema:"homeboy/test-inventory/v1",command:$command,runner:"nextest",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),execution_fingerprint:$execution_fingerprint,inventory_fingerprint:$inventory_fingerprint,tests:[{id:"stable"}]}' > "${dir}/homeboy-ci-results/review-test.test-inventory.json"
  jq -cn --arg phase "${phase}" --arg repository example/repo --arg candidate_sha candidate --arg base_sha base --arg checkout_sha "${checkout_sha}" --arg command 'review test' --arg component "${component}" --arg action_revision action-sha --arg cli_revision "${cli}" --arg binary_sha256 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' --argjson run_attempt 2 --argjson results "${results}" '{phase:$phase,repository:$repository,candidate_sha:$candidate_sha,base_sha:$base_sha,checkout_sha:$checkout_sha,command:$command,component:$component,action_revision:$action_revision,cli_revision:$cli_revision,binary_sha256:$binary_sha256,run_attempt:$run_attempt,results:$results}' > "${dir}/manifest.json"
}

mark_pr_closed() {
  local phase="$1" state="${2:-pr_closed}"
  jq --arg state "${state}" '.execution_state = $state | .results = {}' "${tmp}/artifacts/${phase}/manifest.json" > "${tmp}/manifest.json"
  mv "${tmp}/manifest.json" "${tmp}/artifacts/${phase}/manifest.json"
}

run_case() {
  local expected="$1" label="$2"
  shift 2
  rm -f "${tmp}/output"
  set +e
  PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true PR_ACTIVE=false GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" >/dev/null 2>&1
  local actual=$?
  set -e
  if [ "${actual}" -ne "${expected}" ]; then
    printf 'FAIL: %s (expected exit %s, got %s)\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

run_lazy_case() {
  local expected="$1" label="$2"
  rm -f "${tmp}/output"
  set +e
  PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=candidate-failure PR_ACTIVE=false GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" >/dev/null 2>&1
  local actual=$?
  set -e
  if [ "${actual}" -ne "${expected}" ]; then
    printf 'FAIL: %s (expected exit %s, got %s)\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

payload_pass='{"success":true,"data":{"test_counts":{"failed":0,"errors":0}}}'
payload_one='{"success":false,"data":{"test_counts":{"failed":1,"errors":0}}}'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"pass"}' "${payload_pass}"; write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case 0 'merged PR still reconciles matching immutable passing phases'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"pass"}' "${payload_pass}"
run_lazy_case 0 'passing candidate does not require a baseline artifact'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"
run_lazy_case 1 'failing candidate still requires a baseline artifact'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case 1 'introduced candidate failure remains blocking'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; write_phase baseline base component cli '{"review test":"fail"}' "${payload_one}"
run_case 0 'baseline red is preserved as a non-regression verdict'
grep -F '"baseline_red"' "${tmp}/output" >/dev/null || { printf 'FAIL: baseline red verdict was not published\n'; exit 1; }

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"timeout"}' "${payload_pass}"; write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case 1 'candidate timeout remains blocking'

rm -rf "${tmp}/artifacts"; mkdir -p "${tmp}/artifacts"; write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case 1 'missing candidate artifact fails closed'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; mark_pr_closed candidate
run_case 0 'closed PR before candidate execution produces a neutral lifecycle verdict'
grep -F 'lifecycle-state=pr_closed' "${tmp}/output" >/dev/null || { printf 'FAIL: candidate closure omitted typed lifecycle output\n'; exit 1; }

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; mark_pr_closed candidate
rm -f "${tmp}/output"
set +e
PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true PR_ACTIVE=true GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" >/dev/null 2>&1
active_closed_status=$?
set -e
[ "${active_closed_status}" -eq 1 ] || { printf 'FAIL: active PR accepted pr_closed candidate evidence\n'; exit 1; }
printf 'PASS: active PR fails closed for pr_closed candidate evidence\n'

mkdir -p "${tmp}/bin"
printf '#!/usr/bin/env bash\nprintf "CLOSED"\n' > "${tmp}/bin/gh"
chmod +x "${tmp}/bin/gh"
rm -f "${tmp}/output"
PATH="${tmp}/bin:${PATH}" PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo GITHUB_REPOSITORY=example/repo PR_NUMBER=42 CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true PR_ACTIVE=true GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" >/dev/null
grep -F 'lifecycle-state=pr_closed' "${tmp}/output" >/dev/null || { printf 'FAIL: standalone reconciler did not resolve closed PR state\n'; exit 1; }
printf 'PASS: standalone reconciler loads its PR state helper\n'

# Regression for #13710: rerunning a workflow after its PR merged used to record
# pr_merged candidate evidence but fail when an unauthenticated final probe
# fail-open reported PR_ACTIVE=true. A merged terminal state is now canonical.
rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; mark_pr_closed candidate pr_merged
printf '#!/usr/bin/env bash\nprintf "CLOSED\\t2026-08-31T19:00:00Z"\n' > "${tmp}/bin/gh"
chmod +x "${tmp}/bin/gh"
rm -f "${tmp}/output"
PATH="${tmp}/bin:${PATH}" PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo GITHUB_REPOSITORY=example/repo PR_NUMBER=42 CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true PR_ACTIVE=true PR_STATE=unknown GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" >/dev/null
grep -F 'lifecycle-state=pr_merged' "${tmp}/output" >/dev/null || { printf 'FAIL: merged rerun did not produce a neutral lifecycle verdict\n'; exit 1; }
printf 'PASS: merged PR rerun resolves contradictory active evidence without a red quality gate\n'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"fail"}' "${payload_one}"; write_phase baseline base component cli '{"review test":"fail"}' "${payload_one}"; mark_pr_closed baseline
run_case 0 'closed PR before baseline execution produces a neutral lifecycle verdict'
grep -F 'lifecycle-state=pr_closed' "${tmp}/output" >/dev/null || { printf 'FAIL: baseline closure omitted typed lifecycle output\n'; exit 1; }

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component-a cli '{"review test":"pass"}' "${payload_pass}"; write_phase baseline base component-b cli '{"review test":"pass"}' "${payload_pass}"
run_case 1 'component provenance mismatch fails closed'

rm -rf "${tmp}/artifacts"; write_phase candidate candidate component cli '{"review test":"pass"}' "${payload_pass}"; write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
jq '.binary_sha256 = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"' "${tmp}/artifacts/baseline/manifest.json" > "${tmp}/manifest.json"
mv "${tmp}/manifest.json" "${tmp}/artifacts/baseline/manifest.json"
run_case 1 'binary provenance mismatch fails closed'

for field in repository candidate_sha base_sha command action_revision cli_revision phase; do
  rm -rf "${tmp}/artifacts"
  write_phase candidate candidate component cli '{"review test":"pass"}' "${payload_pass}"
  write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
  jq --arg field "${field}" '.[$field] = "mismatch"' "${tmp}/artifacts/candidate/manifest.json" > "${tmp}/manifest.json"
  mv "${tmp}/manifest.json" "${tmp}/artifacts/candidate/manifest.json"
  run_case 1 "${field} provenance mismatch fails closed"
done

# Baseline starts only after candidate evidence exists, so a passing candidate
# can skip the duplicate command while failed evidence remains comparable.
grep -F '  candidate:' "${WORKFLOW}" >/dev/null
grep -F '  baseline:' "${WORKFLOW}" >/dev/null
grep -F 'needs: [binary, plan]' "${WORKFLOW}" >/dev/null
grep -A3 '^  baseline:' "${WORKFLOW}" | grep -F 'needs: [binary, plan, candidate]' >/dev/null || { printf 'FAIL: baseline can start before candidate evidence exists\n'; exit 1; }
grep -F 'name: Select baseline execution' "${WORKFLOW}" >/dev/null || { printf 'FAIL: baseline does not select execution from candidate evidence\n'; exit 1; }
grep -F "if: steps.baseline-policy.outputs.run == 'true'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: baseline command is not gated by candidate status\n'; exit 1; }
grep -F "'candidate-failure' || 'false'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: reconciliation does not derive lazy baseline necessity\n'; exit 1; }
printf 'PASS: workflow runs baseline only after a non-passing candidate\n'

# shellcheck disable=SC2016
if grep -F 'results="${RESULTS:-{}}"' "${WORKFLOW}" >/dev/null \
  || [ "$(grep -Fc 'results="${RESULTS:-}"' "${WORKFLOW}")" -ne 1 ] \
  || [ "$(grep -Fc "[ -n \"\${results}\" ] || results='{}'" "${WORKFLOW}")" -ne 1 ]; then
  printf 'FAIL: Test shard provenance does not preserve populated result JSON\n'
  exit 1
fi
RESULTS='{"review test":"pass"}'
results="${RESULTS:-}"
[ -n "${results}" ] || results='{}'
jq -e '."review test" == "pass"' <<< "${results}" >/dev/null
RESULTS=''
results="${RESULTS:-}"
[ -n "${results}" ] || results='{}'
jq -e 'type == "object" and length == 0' <<< "${results}" >/dev/null
printf 'PASS: populated and missing phase results remain valid provenance JSON\n'

rm -rf "${tmp}/artifacts"
write_phase candidate candidate component cli '{"review test":"pass"}' "${payload_pass}"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
mkdir -p "${tmp}/artifacts/prior/candidate"
cp -R "${tmp}/artifacts/candidate/." "${tmp}/artifacts/prior/candidate/"
jq '.run_attempt = 1' "${tmp}/artifacts/prior/candidate/manifest.json" > "${tmp}/manifest.json"
mv "${tmp}/manifest.json" "${tmp}/artifacts/prior/candidate/manifest.json"
run_case 0 'reconciliation reuses the newest independently retried phase artifact'

# These are literal GitHub expression contracts, not shell interpolation.
# shellcheck disable=SC2016
grep -F 'name: ${{ matrix.title }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: reconciliation did not preserve required check names\n'; exit 1; }
# shellcheck disable=SC2016
grep -F 'differential-gating: ${{ inputs.differential-gating }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate lost differential scope semantics\n'; exit 1; }
python3 - "${WORKFLOW}" <<'PY'
import sys
import yaml

workflow = yaml.safe_load(open(sys.argv[1]))

def phase_scope(job, name):
    step = next(step for step in workflow["jobs"][job]["steps"] if step.get("name") == name)
    return step["with"]["scope"]

if phase_scope("candidate", "Run candidate ${{ matrix.title }}") != "${{ inputs.scope }}":
    raise SystemExit("FAIL: candidate lost caller scope semantics")
if phase_scope("baseline", "Run baseline ${{ matrix.title }}") != "full":
    raise SystemExit("FAIL: baseline can compare its checkout to itself instead of producing real evidence")
PY
grep -F 'Evaluate PR policy after reconciled quality gates' "${WORKFLOW}" >/dev/null || { printf 'FAIL: reconciled workflow dropped PR policy evaluation\n'; exit 1; }
# shellcheck disable=SC2016
grep -F 'homeboy-differential-candidate-${{ matrix.artifact_key }}-${{ github.run_attempt }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate artifacts are not command- and attempt-scoped\n'; exit 1; }
printf 'PASS: required checks, scope semantics, and post-reconciliation policy are preserved\n'

# The point of the message is that an operator can act on it. A single
# catch-all ("missing, malformed, or does not match") could not distinguish an
# absent artifact from one wrong field, which made a real reconcile failure in
# Extra-Chill/data-machine#3048 undiagnosable without reproducing the run.
run_case_message() {
  local expected_exit="$1" needle="$2" label="$3"
  rm -f "${tmp}/output"
  set +e
  local out
  out="$(PHASE_ARTIFACT_ROOT="${tmp}/artifacts" REPOSITORY=example/repo GITHUB_RUN_ID=42 CANDIDATE_SHA=candidate BASE_SHA=base COMMAND='review test' ARTIFACT_KEY=fixture-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true GITHUB_OUTPUT="${tmp}/output" bash "${RECONCILE}" 2>&1)"
  local actual=$?
  set -e
  if [ "${actual}" -ne "${expected_exit}" ]; then
    printf 'FAIL: %s (expected exit %s, got %s)\n' "${label}" "${expected_exit}" "${actual}"
    printf '%s\n' "${out}"
    exit 1
  fi
  case "${out}" in
    *"${needle}"*) printf 'PASS: %s\n' "${label}" ;;
    *)
      printf 'FAIL: %s (message did not mention %s)\n' "${label}" "${needle}"
      printf '%s\n' "${out}"
      exit 1
      ;;
  esac
}

rm -rf "${tmp}/artifacts"
write_phase candidate wrong-sha component cli '{"review test":"pass"}' "${payload_pass}"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case_message 1 "field 'checkout_sha'" 'a mismatched field is named in the error'
run_case_message 1 "expected 'candidate', artifact has 'wrong-sha'" 'the error reports expected and actual values'

rm -rf "${tmp}/artifacts"
write_phase candidate candidate component cli '{}' "${payload_pass}"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case_message 1 "field 'results'" 'an unusable results object is named, not reported as a generic mismatch'

rm -rf "${tmp}/artifacts"
write_phase candidate candidate '' cli '{"review test":"pass"}' "${payload_pass}"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case_message 1 "field 'component'" 'an empty component is named'

rm -rf "${tmp}/artifacts"
write_phase candidate candidate component '' '{"review test":"pass"}' "${payload_pass}"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case_message 1 "candidate producer artifact 'homeboy-differential-candidate-fixture-key-2'" 'malformed provenance names its producing artifact'
run_case_message 1 "field 'cli_revision'" 'empty CLI provenance remains distinct from a gate failure'
run_case_message 1 'gh run rerun 42 --repo example/repo' 'malformed provenance supplies a complete-workflow rerun command'

rm -rf "${tmp}/artifacts"
mkdir -p "${tmp}/artifacts/candidate"
write_phase baseline base component cli '{"review test":"pass"}' "${payload_pass}"
run_case_message 1 "No candidate provenance artifact" 'a wholly absent candidate artifact reports that it is absent, not silently'

write_aggregate_phase() {
  local root="$1" phase="$2" checkout_sha="$3" command="$4" cli="$5"
  local artifact="homeboy-differential-${phase}-aggregate-key-2"
  local dir="${root}/${artifact}/${phase}"
  mkdir -p "${dir}/homeboy-ci-results"
  jq -cn --arg phase "${phase}" --arg checkout_sha "${checkout_sha}" --arg command "${command}" --arg cli_revision "${cli}" \
    '{phase:$phase,repository:"example/repo",candidate_sha:"candidate",base_sha:"base",checkout_sha:$checkout_sha,command:$command,component:"component",action_revision:"action-sha",cli_revision:$cli_revision,binary_sha256:("a" * 64),run_attempt:2,results:{($command):"pass"}}' \
    > "${dir}/manifest.json"
}

# Regression for #438: all six producers carry the one selected CLI identity,
# so three passing differential gates deterministically remain three passing
# aggregate checks instead of becoming provenance failures.
for command in 'review audit' 'review lint' 'review test'; do
  aggregate_root="${tmp}/aggregate-$(printf '%s' "${command}" | tr ' ' '-')"
  write_aggregate_phase "${aggregate_root}" candidate candidate "${command}" 'homeboy 9.9.9+candidate'
  write_aggregate_phase "${aggregate_root}" baseline base "${command}" 'homeboy 9.9.9+candidate'
  rm -f "${tmp}/aggregate-output"
  PHASE_ARTIFACT_ROOT="${aggregate_root}" REPOSITORY=example/repo GITHUB_RUN_ID=42 CANDIDATE_SHA=candidate BASE_SHA=base COMMAND="${command}" ARTIFACT_KEY=aggregate-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true PR_ACTIVE=false GITHUB_OUTPUT="${tmp}/aggregate-output" \
    bash "${RECONCILE}" >/dev/null
  grep -F "{\"${command}\":\"pass\"}" "${tmp}/aggregate-output" >/dev/null \
    || { printf 'FAIL: %s aggregate did not preserve six-phase passing evidence\n' "${command}"; exit 1; }
done
printf 'PASS: six revision-bound passing phases deterministically produce three passing aggregates\n'

# The shared reconciler is the enforcement path for Audit, Lint, and Test. Prove
# each aggregate emits producer, artifact, and rerun evidence for transported
# malformed provenance rather than degrading to a generic gate failure.
for command in 'review audit' 'review lint' 'review test'; do
  aggregate_root="${tmp}/malformed-$(printf '%s' "${command}" | tr ' ' '-')"
  write_aggregate_phase "${aggregate_root}" candidate candidate "${command}" ''
  write_aggregate_phase "${aggregate_root}" baseline base "${command}" 'homeboy 9.9.9+candidate'
  rm -f "${tmp}/aggregate-output"
  set +e
  aggregate_message="$(PHASE_ARTIFACT_ROOT="${aggregate_root}" REPOSITORY=example/repo GITHUB_RUN_ID=42 CANDIDATE_SHA=candidate BASE_SHA=base COMMAND="${command}" ARTIFACT_KEY=aggregate-key ACTION_REVISION=action-sha RUN_ATTEMPT=2 REQUIRE_BASELINE=true GITHUB_OUTPUT="${tmp}/aggregate-output" bash "${RECONCILE}" 2>&1)"
  aggregate_status=$?
  set -e
  if [ "${aggregate_status}" -eq 0 ]; then
    printf 'FAIL: %s aggregate accepted malformed CLI provenance\n' "${command}"
    exit 1
  fi
  for evidence in \
    "candidate producer artifact 'homeboy-differential-candidate-aggregate-key-2'" \
    "field 'cli_revision'" \
    'gh run rerun 42 --repo example/repo'; do
    case "${aggregate_message}" in
      *"${evidence}"*) ;;
      *) printf 'FAIL: %s malformed aggregate omitted %s\n%s\n' "${command}" "${evidence}" "${aggregate_message}"; exit 1 ;;
    esac
  done
done
printf 'PASS: every aggregate reports malformed provenance with producer, artifact, and rerun evidence\n'

printf 'Reconcile provenance diagnosis checks passed.\n'
