#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT}/.github/workflows/ci.yml"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

cat > "${tmp}/inventory.json" <<'JSON'
{"schema":"homeboy/test-inventory/v1","runner":"fixture","runner_fingerprint":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","workspace_fingerprint":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","inventory_fingerprint":"inventory-a","tests":[{"id":"slow","duration_ms":900},{"id":"medium","duration_ms":500},{"id":"unknown"},{"id":"fast","duration_ms":100}]}
JSON

mkdir -p "${tmp}/bin"
cat > "${tmp}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [ "${HOMEBOY_TEST_INVENTORY_ONLY:-}" = 1 ]; then
  cp "${FIXTURE_INVENTORY}" "${HOMEBOY_TEST_INVENTORY_FILE}"
  exit 0
fi

output=""
printf '%s\n' "$@" > "${FAKE_HOMEBOY_ARGS}"
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done
[ -n "${HOMEBOY_TEST_SHARD_MANIFEST:-}" ] && [ -s "${HOMEBOY_TEST_SHARD_MANIFEST}" ] || exit 98
[ "${HOMEBOY_TEST_SHARD_MANIFEST}" = "${FAKE_EXPECTED_SHARD_MANIFEST}" ] || exit 96
total="$(jq '.tests | length' "${HOMEBOY_TEST_SHARD_MANIFEST}")"
[ "${total}" -gt 0 ] || exit 97
mkdir -p "$(dirname "${output}")"
jq -n --argjson total "${total}" '{schema:"homeboy/command-result/v3",command:"review",success:true,status:"succeeded",exit_code:0,data:{test_counts:{passed:$total,failed:0,skipped:0,total:$total}}}' > "${output}"
SH
chmod +x "${tmp}/bin/homeboy"
PATH="${tmp}/bin:${PATH}" FIXTURE_INVENTORY="${tmp}/inventory.json" HOMEBOY_TEST_INVENTORY_ONLY=1 HOMEBOY_TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" homeboy review test fixture
[ -s "${tmp}/captured-inventory.json" ] || { printf 'FAIL: inventory-only Homeboy contract did not materialize an inventory\n'; exit 1; }

for output in one two; do
  TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/${output}.json" TEST_SHARD_COUNT=2 \
    bash "${ROOT}/scripts/core/shard-tests.sh" plan
done
cmp "${tmp}/one.json" "${tmp}/two.json" || { printf 'FAIL: identical inventories must produce byte-identical plans\n'; exit 1; }
jq -e '[.shards[].tests[]] | sort == ["fast","medium","slow","unknown"]' "${tmp}/one.json" >/dev/null || { printf 'FAIL: plan membership is not exact\n'; exit 1; }
jq -e '[.shards[].tests[]] | length == (unique | length)' "${tmp}/one.json" >/dev/null || { printf 'FAIL: plan assigns a test to more than one shard\n'; exit 1; }
jq -e '.inventory_fingerprint == "inventory-a" and all(.shards[]; .inventory_fingerprint != "inventory-a")' "${tmp}/one.json" >/dev/null || { printf 'FAIL: plan must retain the parent fingerprint while shards fingerprint their projections\n'; exit 1; }
while IFS= read -r shard; do
  shard_id="$(jq -r .id <<< "${shard}")"
  projected="$(jq -cS --arg id "${shard_id}" --slurpfile plan "${tmp}/one.json" '
    ($plan[0].shards[] | select(.id == $id).tests | reduce .[] as $test_id ({}; .[$test_id] = true)) as $selected
    | {schema,runner,runner_fingerprint,workspace_fingerprint,tests:(.tests | map(select($selected[.id])) | sort_by(.id))}
  ' "${tmp}/captured-inventory.json")"
  expected_fingerprint="$(printf '%s' "${projected}" | shasum -a 256 | awk '{print $1}')"
  [ "$(jq -r .inventory_fingerprint <<< "${shard}")" = "${expected_fingerprint}" ] || { printf 'FAIL: %s fingerprint does not bind its selected inventory projection\n' "${shard_id}"; exit 1; }
done < <(jq -c '.shards[]' "${tmp}/one.json")
tampered_fingerprint="$(jq -cS --slurpfile plan "${tmp}/one.json" '
  ($plan[0].shards[0].tests + [$plan[0].shards[1].tests[0]] | reduce .[] as $test_id ({}; .[$test_id] = true)) as $selected
  | {schema,runner,runner_fingerprint,workspace_fingerprint,tests:(.tests | map(select($selected[.id])) | sort_by(.id))}
' "${tmp}/captured-inventory.json" | shasum -a 256 | awk '{print $1}')"
[ "${tampered_fingerprint}" != "$(jq -r '.shards[0].inventory_fingerprint' "${tmp}/one.json")" ] || { printf 'FAIL: changed shard membership must change its projected fingerprint\n'; exit 1; }
if TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/too-many.json" TEST_SHARD_COUNT=5 bash "${ROOT}/scripts/core/shard-tests.sh" plan >/dev/null 2>&1; then
  printf 'FAIL: empty shard plans must be rejected\n'; exit 1
fi
jq -e 'all(.shards[]; .schema == "homeboy/test-shard-manifest/v1" and (.runner == "fixture") and (.tests | all(type == "string")))' "${tmp}/one.json" >/dev/null || { printf 'FAIL: emitted shard manifests do not match the extension contract\n'; exit 1; }

write_shard_pair() {
  local plan="$1" shard_id="$2" result_dir="$3" command="${4:-review test}" failed_count="${5:-0}"
  python3 - "${plan}" "${shard_id}" "${result_dir}" "${command}" "${failed_count}" <<'PY'
import hashlib, json, pathlib, sys
plan_path, shard_id, result_dir, command, failed_count = sys.argv[1:]
plan = json.load(open(plan_path, encoding="utf-8"))
shard = next(value for value in plan["shards"] if value["id"] == shard_id)
tests = sorted(shard["tests"])
failed = tests[:int(failed_count)]
identity = "review test" if command.split()[:2] == ["review", "test"] else "test"
canonical = lambda value: json.dumps(value, sort_keys=True, separators=(",", ":"))
digest = lambda value: hashlib.sha256(canonical(value).encode()).hexdigest()
execution = digest({
    "inventory_fingerprint": shard["inventory_fingerprint"], "runner": shard["runner"],
    "runner_fingerprint": shard["runner_fingerprint"], "source": "shard_manifest",
    "source_id": shard_id, "tests": tests, "workspace_fingerprint": shard["workspace_fingerprint"],
})
inventory = {
    "command": identity, "execution_fingerprint": execution, "runner": shard["runner"],
    "runner_fingerprint": shard["runner_fingerprint"], "schema": "homeboy/test-inventory/v1",
    "tests": [{"id": value} for value in tests], "workspace_fingerprint": shard["workspace_fingerprint"],
}
inventory["inventory_fingerprint"] = digest(inventory)
outcomes = {
    "schema": "homeboy/test-outcomes/v1", "command": identity, "runner": shard["runner"],
    "runner_fingerprint": shard["runner_fingerprint"], "workspace_fingerprint": shard["workspace_fingerprint"],
    "execution_fingerprint": execution, "inventory_fingerprint": inventory["inventory_fingerprint"],
    "failed_test_ids": failed,
}
stem = "-".join(command.split())
directory = pathlib.Path(result_dir)
(directory / f"{stem}.test-inventory.json").write_text(json.dumps(inventory), encoding="utf-8")
(directory / f"{stem}.test-outcomes.json").write_text(json.dumps(outcomes), encoding="utf-8")
PY
}
jq -n '{schema:"homeboy/test-inventory/v1",runner:"fixture",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),inventory_fingerprint:"large-inventory-a",tests:[range(0; 12000) | {id:("test-" + tostring + ("x" * 160)),duration_ms:1}]}' > "${tmp}/large-inventory.json"
[ "$(wc -c < "${tmp}/large-inventory.json")" -gt 1048576 ] || { printf 'FAIL: large inventory must exceed a conservative argv-sized payload\n'; exit 1; }
for output in large-one large-two; do
  TEST_INVENTORY_FILE="${tmp}/large-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/${output}.json" TEST_SHARD_COUNT=2 \
    bash "${ROOT}/scripts/core/shard-tests.sh" plan
done
cmp "${tmp}/large-one.json" "${tmp}/large-two.json" || { printf 'FAIL: large inventories must produce byte-identical plans\n'; exit 1; }
jq -e '[.shards[].tests[]] | length == 12000' "${tmp}/large-one.json" >/dev/null || { printf 'FAIL: large plan membership is not exact\n'; exit 1; }
large_plan_digest="$(jq -r .plan_digest "${tmp}/large-one.json")"
large_inventory_digest="$(jq -r .inventory_digest "${tmp}/large-one.json")"
for shard in shard-1 shard-2; do
  mkdir -p "${tmp}/large-artifacts/${shard}/candidate/homeboy-ci-results"
  jq -cn --arg shard "${shard}" --arg inventory "${large_inventory_digest}" --arg plan "${large_plan_digest}" \
    '{phase:"candidate",command:"review test",shard_id:$shard,inventory_digest:$inventory,plan_digest:$plan,run_attempt:2,results:{"review test":"pass"}}' > "${tmp}/large-artifacts/${shard}/candidate/manifest.json"
  total="$(jq -r --arg id "${shard}" '.shards[] | select(.id == $id) | .tests | length' "${tmp}/large-one.json")"
  jq -n --arg id "${shard}" --argjson total "${total}" --slurpfile plan "${tmp}/large-one.json" '
    {schema:"homeboy/command-result/v3",command:"review",success:true,status:"succeeded",exit_code:0,
     data:{test_counts:{passed:$total,failed:0,skipped:0,total:$total},test_ids:($plan[0].shards[] | select(.id == $id) | .tests)}}
  ' > "${tmp}/large-artifacts/${shard}/candidate/homeboy-ci-results/review-test.json"
  write_shard_pair "${tmp}/large-one.json" "${shard}" "${tmp}/large-artifacts/${shard}/candidate/homeboy-ci-results"
done
large_payload_bytes=$(( $(wc -c < "${tmp}/large-artifacts/shard-1/candidate/homeboy-ci-results/review-test.json") + $(wc -c < "${tmp}/large-artifacts/shard-2/candidate/homeboy-ci-results/review-test.json") ))
[ "${large_payload_bytes}" -gt 1048576 ] || { printf 'FAIL: aggregate payloads must exceed a conservative argv-sized payload\n'; exit 1; }
for output in large-aggregate-one large-aggregate-two; do
  TEST_SHARD_ARTIFACT_ROOT="${tmp}/large-artifacts" TEST_SHARD_PLAN_FILE="${tmp}/large-one.json" TEST_INVENTORY_FILE="${tmp}/large-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/${output}" RUN_ATTEMPT=2 \
    bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
done
cmp "${tmp}/large-aggregate-one/review-test.json" "${tmp}/large-aggregate-two/review-test.json" || { printf 'FAIL: large shard payloads must produce byte-identical aggregates\n'; exit 1; }
jq -e '.data.test_counts.total == 12000 and .data.shard_count == 2' "${tmp}/large-aggregate-one/review-test.json" >/dev/null || { printf 'FAIL: large shard payloads did not aggregate completely\n'; exit 1; }
jq -n '{schema:"homeboy/test-inventory/v1",runner:"fixture",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),inventory_fingerprint:"many-shard-inventory-a",tests:[range(0; 32) | {id:("test-" + tostring),duration_ms:1}]}' > "${tmp}/many-shard-inventory.json"
TEST_INVENTORY_FILE="${tmp}/many-shard-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/many-shard-plan.json" TEST_SHARD_COUNT=32 bash "${ROOT}/scripts/core/shard-tests.sh" plan
many_plan_digest="$(jq -r .plan_digest "${tmp}/many-shard-plan.json")"
many_inventory_digest="$(jq -r .inventory_digest "${tmp}/many-shard-plan.json")"
while IFS= read -r shard; do
  mkdir -p "${tmp}/many-shard-artifacts/${shard}/candidate/homeboy-ci-results"
  jq -cn --arg shard "${shard}" --arg inventory "${many_inventory_digest}" --arg plan "${many_plan_digest}" \
    '{phase:"candidate",command:"review test",shard_id:$shard,inventory_digest:$inventory,plan_digest:$plan,run_attempt:2,results:{"review test":"pass"}}' > "${tmp}/many-shard-artifacts/${shard}/candidate/manifest.json"
  jq -n '{schema:"homeboy/command-result/v3",command:"review",success:true,status:"succeeded",exit_code:0,data:{test_counts:{passed:1,failed:0,skipped:0,total:1}}}' > "${tmp}/many-shard-artifacts/${shard}/candidate/homeboy-ci-results/review-test.json"
  write_shard_pair "${tmp}/many-shard-plan.json" "${shard}" "${tmp}/many-shard-artifacts/${shard}/candidate/homeboy-ci-results"
done < <(jq -r '.shards[].id' "${tmp}/many-shard-plan.json")
real_jq="$(command -v jq)"
cat > "${tmp}/bin/jq" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$#" >> "${JQ_ARGC_LOG}"
exec "${REAL_JQ}" "$@"
SH
chmod +x "${tmp}/bin/jq"
PATH="${tmp}/bin:${PATH}" REAL_JQ="${real_jq}" JQ_ARGC_LOG="${tmp}/jq-argc.log" TEST_SHARD_ARTIFACT_ROOT="${tmp}/many-shard-artifacts" TEST_SHARD_PLAN_FILE="${tmp}/many-shard-plan.json" TEST_INVENTORY_FILE="${tmp}/many-shard-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/many-shard-output" RUN_ATTEMPT=2 \
  bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
max_jq_argc=0
while IFS= read -r jq_argc; do
  if [ "${jq_argc}" -gt "${max_jq_argc}" ]; then max_jq_argc="${jq_argc}"; fi
done < "${tmp}/jq-argc.log"
[ "${max_jq_argc}" -lt 32 ] || { printf 'FAIL: aggregate jq argv contains one pathname per shard\n'; exit 1; }
rm "${tmp}/bin/jq"
jq -e '.data.test_counts.total == 32 and .data.shard_count == 32' "${tmp}/many-shard-output/review-test.json" >/dev/null || { printf 'FAIL: many-shard aggregate did not preserve complete evidence\n'; exit 1; }
jq '.workspace_fingerprint = "workspace-base"' "${tmp}/captured-inventory.json" > "${tmp}/base-inventory.json"
TEST_INVENTORY_FILE="${tmp}/base-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/base-plan.json" TEST_SHARD_COUNT=2 bash "${ROOT}/scripts/core/shard-tests.sh" plan
[ "$(jq -r .plan_digest "${tmp}/one.json")" != "$(jq -r .plan_digest "${tmp}/base-plan.json")" ] || { printf 'FAIL: candidate and baseline inventories must produce distinct plans\n'; exit 1; }
printf 'PASS: deterministic duration-balanced plan has exact membership\n'

# The shape production actually emits. `cargo nextest list` enumerates tests
# without running them, so the Rust extension's inventory carries NO duration_ms
# on any test and LPT falls back to the shared default -- i.e. equal-count
# partitioning. Every existing plan fixture above carries durations, so the one
# path CI always takes was untested (homeboy#11751 W1-7).
jq -n '{schema:"homeboy/test-inventory/v1",runner:"fixture",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),inventory_fingerprint:"durationless-a",tests:[range(0; 40) | {id:("test-" + tostring)}]}' > "${tmp}/durationless-inventory.json"
TEST_INVENTORY_FILE="${tmp}/durationless-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/durationless-plan.json" TEST_SHARD_COUNT=4 bash "${ROOT}/scripts/core/shard-tests.sh" plan
jq -e '[.shards[].tests | length] | (unique | length) == 1 and .[0] == 10' "${tmp}/durationless-plan.json" >/dev/null || {
  printf 'FAIL: an inventory without durations must partition into equal counts\n'; exit 1; }
jq -e '[.shards[].tests[]] | (length == 40) and ((unique | length) == 40)' "${tmp}/durationless-plan.json" >/dev/null || {
  printf 'FAIL: durationless partitioning lost or duplicated tests\n'; exit 1; }
# Determinism matters more than balance here: the manifest fingerprints that
# gate shard replay are computed from membership.
TEST_INVENTORY_FILE="${tmp}/durationless-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/durationless-plan-2.json" TEST_SHARD_COUNT=4 bash "${ROOT}/scripts/core/shard-tests.sh" plan
[ "$(jq -r .plan_digest "${tmp}/durationless-plan.json")" = "$(jq -r .plan_digest "${tmp}/durationless-plan-2.json")" ] || {
  printf 'FAIL: durationless partitioning is not deterministic\n'; exit 1; }
printf 'PASS: an inventory without durations partitions into deterministic equal counts\n'

# Forward compatibility: if a producer ever does emit durations, weight must beat
# count, so the LPT path is not quietly dead code.
jq -n '{schema:"homeboy/test-inventory/v1",runner:"fixture",runner_fingerprint:("a" * 64),workspace_fingerprint:("b" * 64),inventory_fingerprint:"weighted-a",tests:[{id:"heavy",duration_ms:10000},{id:"light-1",duration_ms:100},{id:"light-2",duration_ms:100},{id:"light-3",duration_ms:100}]}' > "${tmp}/weighted-inventory.json"
TEST_INVENTORY_FILE="${tmp}/weighted-inventory.json" TEST_SHARD_PLAN_FILE="${tmp}/weighted-plan.json" TEST_SHARD_COUNT=2 bash "${ROOT}/scripts/core/shard-tests.sh" plan
jq -e '[.shards[] | select(.tests == ["heavy"])] | length == 1' "${tmp}/weighted-plan.json" >/dev/null || {
  printf 'FAIL: a duration-bearing inventory must isolate the heavy test rather than split by count\n'; exit 1; }
printf 'PASS: duration-bearing inventories still balance by weight, not count\n'

plan_digest="$(jq -r .plan_digest "${tmp}/one.json")"
inventory_digest="$(jq -r .inventory_digest "${tmp}/one.json")"
shard_manifest="${tmp}/shard-1.json"
jq --arg id shard-1 '.shards[] | select(.id == $id)' "${tmp}/one.json" > "${shard_manifest}"
mkdir -p "${tmp}/replay-workspace"
PATH="${tmp}/bin:${PATH}" GITHUB_ACTION_PATH="${ROOT}" GITHUB_WORKSPACE="${tmp}/replay-workspace" GITHUB_OUTPUT="${tmp}/replay-output" GITHUB_ENV="${tmp}/replay-env" RESOLVED_COMMANDS='review test' COMPONENT_NAME=fixture HOMEBOY_TEST_SHARD_MANIFEST="${shard_manifest}" FAKE_EXPECTED_SHARD_MANIFEST="${shard_manifest}" FAKE_HOMEBOY_ARGS="${tmp}/replay-args" \
  bash "${ROOT}/scripts/core/run-homeboy-commands.sh"
replay_result="${tmp}/replay-workspace/homeboy-ci-results/review-test.json"
replay_total="$(jq '.data.test_counts.total' "${replay_result}")"
[ "${replay_total}" -gt 0 ] || { printf 'FAIL: non-empty shard manifest replay did not execute tests\n'; exit 1; }
[ "${replay_total}" = "$(jq '.tests | length' "${shard_manifest}")" ] || { printf 'FAIL: replay total does not match assigned shard membership\n'; exit 1; }
if grep -Fx -- "$(jq -r '.tests[0]' "${shard_manifest}")" "${tmp}/replay-args" >/dev/null; then
  printf 'FAIL: action expanded an assigned test ID into the Homeboy command argv\n'; exit 1
fi
printf 'PASS: non-empty shard manifest is passed by path and replays assigned tests through the action command wrapper\n'
for phase in candidate baseline; do
  for shard in shard-1 shard-2; do
    mkdir -p "${tmp}/artifacts/${phase}-${shard}/${phase}/homeboy-ci-results"
    jq -cn --arg phase "${phase}" --arg command 'review test' --arg shard "${shard}" --arg inventory "${inventory_digest}" --arg plan "${plan_digest}" \
      '{phase:$phase,command:$command,shard_id:$shard,inventory_digest:$inventory,plan_digest:$plan,run_attempt:2,results:{"review test":"pass"}}' > "${tmp}/artifacts/${phase}-${shard}/${phase}/manifest.json"
    total="$(jq -r --arg id "${shard}" '.shards[] | select(.id == $id) | .tests | length' "${tmp}/one.json")"
    jq -cn --argjson total "${total}" '{schema:"homeboy/command-result/v3",command:"review",success:true,status:"succeeded",exit_code:0,data:{test_counts:{passed:$total,failed:0,skipped:0,total:$total}}}' > "${tmp}/artifacts/${phase}-${shard}/${phase}/homeboy-ci-results/review-test.json"
    write_shard_pair "${tmp}/one.json" "${shard}" "${tmp}/artifacts/${phase}-${shard}/${phase}/homeboy-ci-results"
  done
  TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE="${phase}" TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/${phase}-output" RUN_ATTEMPT=2 \
    bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
done
cp "${replay_result}" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/candidate-replay-output" RUN_ATTEMPT=2 \
  bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
jq -e '.data.test_counts.total == 4' "${tmp}/candidate-replay-output/review-test.json" >/dev/null || { printf 'FAIL: aggregate did not include replayed shard evidence\n'; exit 1; }
jq -e '.data.shard_plan_digest == $digest' --arg digest "${plan_digest}" "${tmp}/candidate-replay-output/review-test.json" >/dev/null || { printf 'FAIL: aggregation did not preserve immutable plan identity\n'; exit 1; }
jq -e '."review test" == "pass"' "${tmp}/candidate-output/results.json" >/dev/null
jq -e '.data.test_counts.total == 4' "${tmp}/candidate-output/review-test.json" >/dev/null || { printf 'FAIL: aggregation did not sum actual structured shard counts\n'; exit 1; }
jq -e '.schema == "homeboy/test-inventory/v1" and .command == "review test" and ([.tests[].id] | sort == ["fast","medium","slow","unknown"]) and (.inventory_fingerprint | test("^[0-9a-f]{64}$")) and (.execution_fingerprint | test("^[0-9a-f]{64}$"))' "${tmp}/candidate-output/review-test.test-inventory.json" >/dev/null || { printf 'FAIL: aggregate inventory sidecar is not canonical and complete\n'; exit 1; }
jq -e '.schema == "homeboy/test-outcomes/v1" and .command == "review test" and .failed_test_ids == []' "${tmp}/candidate-output/review-test.test-outcomes.json" >/dev/null || { printf 'FAIL: aggregate outcomes sidecar is not canonical and complete\n'; exit 1; }
cmp "${tmp}/candidate-output/review-test.test-inventory.json" "${tmp}/baseline-output/review-test.test-inventory.json" >/dev/null || { printf 'FAIL: identical shard provenance did not produce deterministic aggregate evidence\n'; exit 1; }

# Full configured commands own filenames and execution, while producer sidecars
# retain the canonical command identity reported by Homeboy.
for shard in shard-1 shard-2; do
  source_dir="${tmp}/artifacts/candidate-${shard}/candidate"
  target_dir="${tmp}/suffixed-artifacts/${shard}/candidate"
  mkdir -p "${target_dir}/homeboy-ci-results"
  jq '.command = "review test package-a" | .results = {"review test package-a":"pass"}' "${source_dir}/manifest.json" > "${target_dir}/manifest.json"
  cp "${source_dir}/homeboy-ci-results/review-test.json" "${target_dir}/homeboy-ci-results/review-test-package-a.json"
  write_shard_pair "${tmp}/one.json" "${shard}" "${target_dir}/homeboy-ci-results" 'review test package-a'
done
TEST_SHARD_ARTIFACT_ROOT="${tmp}/suffixed-artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test package-a' TEST_SHARD_OUTPUT_DIR="${tmp}/suffixed-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
jq -e '.command == "review test"' "${tmp}/suffixed-output/review-test-package-a.test-inventory.json" >/dev/null || { printf 'FAIL: suffixed aggregate did not retain canonical producer command identity\n'; exit 1; }
[ -f "${tmp}/suffixed-output/review-test-package-a.json" ] || { printf 'FAIL: suffixed aggregate did not retain the full command output stem\n'; exit 1; }
printf 'PASS: suffixed Test commands retain full execution stems and canonical evidence identity\n'

# A malformed or provenance-crossed member of an otherwise complete pair must
# invalidate the whole aggregate rather than silently dropping that shard.
pair="${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.test-outcomes.json"
cp "${pair}" "${tmp}/valid-shard-outcomes.json"
jq '.runner_fingerprint = ("f" * 64)' "${pair}" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${pair}"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/malformed-pair-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: mismatched shard sidecar provenance must fail closed\n'; exit 1
fi
cp "${tmp}/valid-shard-outcomes.json" "${pair}"
printf 'PASS: malformed and provenance-mismatched shard pairs fail closed\n'

# The producing shard validates its own uploaded terminal provenance after
# publication. Only a coherent, complete pass remains green; all other cases
# fail locally while the aggregate can still consume the uploaded artifact.
terminal_root="${tmp}/terminal/candidate"
mkdir -p "${terminal_root}/homeboy-ci-results"
jq -cn --arg inventory "${inventory_digest}" --arg plan "${plan_digest}" \
  '{phase:"candidate",command:"review test",shard_id:"shard-1",inventory_digest:$inventory,plan_digest:$plan,run_attempt:2,results:{"review test":"pass"}}' > "${terminal_root}/manifest.json"
terminal_total="$(jq -r '.shards[] | select(.id == "shard-1") | .tests | length' "${tmp}/one.json")"
jq -n --argjson total "${terminal_total}" '{schema:"homeboy/command-result/v3",command:"review",success:true,status:"succeeded",exit_code:0,data:{test_counts:{passed:$total,failed:0,skipped:0,total:$total}}}' > "${terminal_root}/homeboy-ci-results/review-test.json"
validate_terminal() {
  TEST_SHARD_TERMINAL_ROOT="${terminal_root}" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_ID=shard-1 RUN_ATTEMPT=2 \
    bash "${ROOT}/scripts/core/shard-tests.sh" validate-terminal
}
validate_terminal || { printf 'FAIL: validated passing shard terminal provenance did not remain green\n'; exit 1; }
jq '.results["review test"] = "fail"' "${terminal_root}/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${terminal_root}/manifest.json"
jq '.success = false | .status = "failed" | .exit_code = 1 | .data.test_counts.failed = 1 | .data.test_counts.passed -= 1' "${terminal_root}/homeboy-ci-results/review-test.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${terminal_root}/homeboy-ci-results/review-test.json"
if validate_terminal >/dev/null 2>&1; then
  printf 'FAIL: green action step with failed structured shard result remained green\n'; exit 1
fi
jq '.results["review test"] = "pass"' "${terminal_root}/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${terminal_root}/manifest.json"
if validate_terminal >/dev/null 2>&1; then
  printf 'FAIL: contradictory passing manifest and failed structured result remained green\n'; exit 1
fi
printf '%s\n' '{malformed' > "${terminal_root}/homeboy-ci-results/review-test.json"
if validate_terminal >/dev/null 2>&1; then
  printf 'FAIL: malformed shard terminal result remained green\n'; exit 1
fi
rm "${terminal_root}/homeboy-ci-results/review-test.json"
if validate_terminal >/dev/null 2>&1; then
  printf 'FAIL: missing shard terminal result remained green\n'; exit 1
fi
printf 'PASS: shard terminal provenance only permits validated pass evidence\n'

mkdir -p "${tmp}/artifacts/candidate-shard-1-attempt-1/candidate/homeboy-ci-results"
cp "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json" "${tmp}/artifacts/candidate-shard-1-attempt-1/candidate/homeboy-ci-results/review-test.json"
jq '.run_attempt = 1 | .results["review test"] = "timeout"' "${tmp}/artifacts/candidate-shard-1/candidate/manifest.json" > "${tmp}/artifacts/candidate-shard-1-attempt-1/candidate/manifest.json"
jq '.run_attempt = 1' "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json"
TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/mixed-attempt-output" RUN_ATTEMPT=2 \
  bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
jq -e '.success == true and .data.test_counts.total == 4' "${tmp}/mixed-attempt-output/review-test.json" >/dev/null || { printf 'FAIL: mixed-attempt shard evidence did not select the newest result per shard\n'; exit 1; }
cp -R "${tmp}/artifacts/candidate-shard-2" "${tmp}/artifacts/candidate-shard-2-duplicate"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/ambiguous-attempt-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: duplicate evidence within the newest shard attempt must fail closed\n'; exit 1
fi
rm -rf "${tmp}/artifacts/candidate-shard-2-duplicate" "${tmp}/artifacts/candidate-shard-1-attempt-1"
jq '.run_attempt = 2' "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json"
printf 'PASS: mixed-attempt reruns retain prior shards and supersede only rerun evidence\n'
cp "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json" "${tmp}/passing-shard.json"
jq '.data.test_counts.failed = 1 | .data.test_counts.passed -= 1' "${tmp}/passing-shard.json" > "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
mkdir "${tmp}/payload-stream-tmp"
if TMPDIR="${tmp}/payload-stream-tmp" TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/false-pass-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: passing shard evidence with failed counts must fail closed\n'; exit 1
fi
rmdir "${tmp}/payload-stream-tmp" || { printf 'FAIL: aggregate leaked a payload stream after validation failure\n'; exit 1; }
cp "${tmp}/passing-shard.json" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
cp "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json" "${tmp}/valid-passing-shard.json"
jq '.status = "failed"' "${tmp}/valid-passing-shard.json" > "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/incoherent-status-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: passing shard evidence with failed status must fail closed\n'; exit 1
fi
jq '.data.test_counts.passed = 0.5 | .data.test_counts.total = 0.5' "${tmp}/valid-passing-shard.json" > "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/fractional-count-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: fractional structured counts must fail closed\n'; exit 1
fi
cp "${tmp}/valid-passing-shard.json" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
total="$(jq -r '.data.test_counts.total' "${tmp}/passing-shard.json")"
jq --argjson total "$((total - 1))" '.data.test_counts.passed = $total | .data.test_counts.total = $total' "${tmp}/passing-shard.json" > "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/incomplete-pass-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: passing shard evidence with incomplete planned membership must fail closed\n'; exit 1
fi
cp "${tmp}/passing-shard.json" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
for phase in candidate baseline; do
  jq '.results["review test"] = "fail"' "${tmp}/artifacts/${phase}-shard-1/${phase}/manifest.json" > "${tmp}/bad.json"
  mv "${tmp}/bad.json" "${tmp}/artifacts/${phase}-shard-1/${phase}/manifest.json"
  jq '.success = false | .status = "failed" | .exit_code = 1 | .data.test_counts.failed = 1 | .data.test_counts.passed -= 1' "${tmp}/artifacts/${phase}-shard-1/${phase}/homeboy-ci-results/review-test.json" > "${tmp}/bad.json"
  mv "${tmp}/bad.json" "${tmp}/artifacts/${phase}-shard-1/${phase}/homeboy-ci-results/review-test.json"
  write_shard_pair "${tmp}/one.json" shard-1 "${tmp}/artifacts/${phase}-shard-1/${phase}/homeboy-ci-results" 'review test' 1
  TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE="${phase}" TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/${phase}-failed-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
  jq -e '."review test" == "fail"' "${tmp}/${phase}-failed-output/results.json" >/dev/null || { printf 'FAIL: failed shard did not retain a failure result\n'; exit 1; }
done
printf 'PASS: complete provenance-bound shard sets aggregate into Test\n'

# Every failed shard contributes its own observed identities to the aggregate.
jq '.results["review test"] = "fail"' "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json"
jq '.success = false | .status = "failed" | .exit_code = 1 | .data.test_counts.failed = 1 | .data.test_counts.passed -= 1' "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.json"
write_shard_pair "${tmp}/one.json" shard-2 "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results" 'review test' 1
TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/multi-failed-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate
jq -e '.failed_test_ids | length == 2 and (unique | length) == 2' "${tmp}/multi-failed-output/review-test.test-outcomes.json" >/dev/null || { printf 'FAIL: aggregate did not union observed failures from every failed shard\n'; exit 1; }
printf 'PASS: multiple failed shards aggregate every observed failed identity\n'

jq '.results["review test"] = "fail"' "${tmp}/artifacts/candidate-shard-1/candidate/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-1/candidate/manifest.json"
jq '{schema,command,success:false,status:"failed",exit_code:1,data:{test_counts:{passed:0,failed:0,skipped:0,total:0}}}' "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/zero-count-failed-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: failed shard without observed failure evidence must fail closed\n'; exit 1
fi
cp "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json" "${tmp}/valid-failed-shard.json"
jq '.exit_code = 0' "${tmp}/valid-failed-shard.json" > "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/incoherent-exit-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: failed shard evidence with zero exit code must fail closed\n'; exit 1
fi
cp "${tmp}/valid-failed-shard.json" "${tmp}/artifacts/candidate-shard-1/candidate/homeboy-ci-results/review-test.json"
printf 'PASS: failed shard without observed failure identities fails closed\n'

jq '.results["review test"] = "timeout"' "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/candidate-shard-2/candidate/manifest.json"
rm "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.json"
rm -f "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.test-inventory.json" "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.test-outcomes.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/timeout-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: timeout without current paired evidence must fail closed\n'; exit 1
fi
jq -n '{schema:"homeboy/command-result/v3",command:"review",success:false,status:"failed",exit_code:124,summary:"test phase timed out before reporting test counts",data:{failure:{category:"infrastructure",phase:"test"}}}' > "${tmp}/artifacts/candidate-shard-2/candidate/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=candidate TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/structured-timeout-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: structured timeout without paired identity evidence must fail closed\n'; exit 1
fi

jq '.data.test_counts.total = 999' "${tmp}/artifacts/baseline-shard-2/baseline/homeboy-ci-results/review-test.json" > "${tmp}/bad.json"
mv "${tmp}/bad.json" "${tmp}/artifacts/baseline-shard-2/baseline/homeboy-ci-results/review-test.json"
if TEST_SHARD_ARTIFACT_ROOT="${tmp}/artifacts" TEST_SHARD_PLAN_FILE="${tmp}/one.json" TEST_INVENTORY_FILE="${tmp}/captured-inventory.json" TEST_SHARD_PHASE=baseline TEST_SHARD_COMMAND='review test' TEST_SHARD_OUTPUT_DIR="${tmp}/bad-output" RUN_ATTEMPT=2 bash "${ROOT}/scripts/core/shard-tests.sh" aggregate >/dev/null 2>&1; then
  printf 'FAIL: false structured counts must fail closed\n'; exit 1
fi
printf 'PASS: missing, mismatched, failed, and timed-out shards fail closed\n'

grep -F 'needs: [reconcile, reconcile-test-shards, plan]' "${WORKFLOW}" >/dev/null || { printf 'FAIL: policy does not wait for the sharded Test verdict and pinned plan\n'; exit 1; }
grep -F "needs.reconcile-test-shards.result == 'success'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: policy can bypass the inventory-routed Test verdict\n'; exit 1; }
# Every terminal upstream result must reach the final PR-state guard. It then
# suppresses only closed or merged PRs; active cancellations fail closed.
grep -F 'always() && needs.plan.outputs.test-shards-enabled' "${WORKFLOW}" >/dev/null || { printf 'FAIL: sharded Test reconciliation skips active cancellation handling\n'; exit 1; }
if [ "$(grep -c 'uses: taiki-e/install-action@nextest' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: Rust candidate plan/replay jobs do not all install cargo-nextest\n'; exit 1
fi
if [ "$(grep -c "continue-on-error: true" "${WORKFLOW}")" -lt 6 ]; then
  printf 'FAIL: inventory planning transport is not isolated from normal Test verdict enforcement\n'; exit 1
fi
# Three NEXTEST_PROFILE declarations, not two: the archive is BUILT with the
# same profile the shards RUN with. A profile mismatch between build and replay
# would silently change which binaries nextest produces versus expects.
if [ "$(grep -c "HOMEBOY_RUST_NEXTEST_FALLBACK: '0'" "${WORKFLOW}")" -ne 2 ] || [ "$(grep -c 'NEXTEST_PROFILE: ci' "${WORKFLOW}")" -ne 3 ]; then
  printf 'FAIL: Rust shard jobs do not require nextest with the CI profile\n'; exit 1
fi

# The archive must be produced once and consumed by the shards. If either side
# disappears, every shard silently reverts to a full-workspace compile -- the
# 7.8-minutes-to-run-4-seconds shape this replaced (homeboy#10997).
grep -F 'cargo nextest archive --workspace --archive-file' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate Test shards are not built once into an archive\n'; exit 1; }
if [ "$(grep -c 'HOMEBOY_RUST_NEXTEST_ARCHIVE' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: the Test archive is not both produced for inventory and consumed by shard replay\n'; exit 1
fi
if [ "$(grep -c 'HOMEBOY_RUST_TEST_RUNNER: nextest' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: Rust shard jobs do not explicitly select nextest\n'; exit 1
fi
if [ "$(grep -c 'prepare-test-shard-workspace.sh' "${WORKFLOW}")" -ne 6 ]; then
  printf 'FAIL: candidate binary and Test shard jobs do not reserve and restore action-owned paths symmetrically\n'; exit 1
fi
if [ "$(grep -c 'Refusing to overwrite consumer path reserved for the Homeboy Action checkout' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: candidate Test shard jobs do not reject action checkout collisions\n'; exit 1
fi
# The sharded Test path has no baseline phase: a baseline is only meaningful for
# differential gating, which the sharded path does not perform. Assert the DAG
# stays candidate-only so the dead-branch class that produced homeboy#10997
# cannot grow back.
for dead_job in baseline-test-plan baseline-test-shards; do
  grep -qE "^  ${dead_job}:" "${WORKFLOW}" && { printf 'FAIL: sharded Test path reintroduced %s\n' "${dead_job}"; exit 1; }
done
grep -F 'homeboy-baseline-results' "${WORKFLOW}" >/dev/null && { printf 'FAIL: sharded Test reconciliation reintroduced baseline aggregate handling\n'; exit 1; }

# Exactly one step may enforce the sharded verdict, and it must not be gated on a
# baseline. A baseline-shaped guard here can never fire, and previously meant a
# non-empty baseline-command left the job green with no enforcement at all.
enforce_steps="$(grep -c 'enforce-final-status.sh' "${WORKFLOW}")"
[ "${enforce_steps}" -eq 1 ] || { printf 'FAIL: sharded Test must have exactly one enforcement step, got %s\n' "${enforce_steps}"; exit 1; }
enforce_guard="$(grep -A2 'name: Enforce sharded Test result' "${WORKFLOW}")"
printf '%s\n' "${enforce_guard}" | grep -qF "needs.candidate-test-result.result == 'success'" || { printf 'FAIL: sharded Test enforcement is not gated on the aggregate result\n'; exit 1; }
if printf '%s\n' "${enforce_guard}" | grep -qF "steps.final-pr-state.outputs.active != 'false'"; then
  printf 'FAIL: sharded Test enforcement skips immutable evidence after PR closure\n'
  exit 1
fi
grep -A2 'name: Enforce sharded Test result' "${WORKFLOW}" | grep -q 'baseline' && { printf 'FAIL: sharded Test enforcement is gated on a baseline that never runs\n'; exit 1; }

# shellcheck disable=SC2016
candidate_inventory_scopes="$(grep -A30 'name: Configure candidate Test shards' "${WORKFLOW}" | grep -F -c 'scope: ${{ inputs.scope }}')"
[ "${candidate_inventory_scopes}" -eq 1 ] || { printf 'FAIL: candidate inventory planning must preserve caller scope, got %s calls\n' "${candidate_inventory_scopes}"; exit 1; }
replay_scopes="$(grep -A30 -E 'name: Run candidate Test shard' "${WORKFLOW}" | grep -c 'scope: full')"
[ "${replay_scopes}" -eq 1 ] || { printf 'FAIL: candidate manifest replay must use full scope, got %s calls\n' "${replay_scopes}"; exit 1; }
if [ "$(grep -c 'scope: full' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: only immutable manifest replay and differential baseline comparison may force full scope\n'; exit 1
fi
grep -F 'prepare-test-shard-workspace.sh prepare .homeboy-action' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate binary setup does not isolate the action checkout\n'; exit 1; }
grep -F "steps.prepare-binary-workspace.outcome == 'success'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate binary setup does not always restore its exact Git exclusions\n'; exit 1; }
grep -F 'name: Write candidate Test inventory failure provenance' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate inventory failures do not preserve provenance\n'; exit 1; }
# shellcheck disable=SC2016
grep -F 'name: homeboy-test-inventory-failure-${{ github.run_attempt }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate inventory failure provenance is not artifact-scoped\n'; exit 1; }
if [ "$(grep -c 'resolve-run-artifact.sh homeboy-test-shard-plan' "${WORKFLOW}")" -ne 2 ]; then
  printf 'FAIL: shard execution and aggregation do not resolve the newest available plan artifact\n'; exit 1
fi
grep -F 'resolve-run-artifact.sh homeboy-test-archive' "${WORKFLOW}" >/dev/null || { printf 'FAIL: shard execution does not resolve the newest available archive artifact\n'; exit 1; }
# shellcheck disable=SC2016
grep -F 'name: ${{ steps.test-plan-artifact.outputs.artifact-name }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: shard jobs do not consume the resolved plan artifact\n'; exit 1; }
grep -F "needs.candidate-test-plan.result == 'success'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate shard work can start without a successful inventory plan\n'; exit 1; }
aggregate_condition="$(grep -A3 '^  candidate-test-result:' "${WORKFLOW}" | grep '^    if:')"
printf '%s\n' "${aggregate_condition}" | grep -q 'always()' || { printf 'FAIL: candidate shard aggregation does not run after a failed producing shard\n'; exit 1; }
if printf '%s\n' "${aggregate_condition}" | grep -q 'needs.candidate-test-shards.result'; then
  printf 'FAIL: candidate shard aggregation is gated on the producing shard result\n'
  exit 1
fi
grep -F 'name: Report candidate Test inventory generation failure' "${WORKFLOW}" >/dev/null || { printf 'FAIL: Test does not report candidate inventory generation failures\n'; exit 1; }
grep -F "if: needs.candidate-test-plan.result == 'success'" "${WORKFLOW}" >/dev/null || { printf 'FAIL: Test artifact downloads are not gated on a successful candidate plan\n'; exit 1; }
grep -F "Candidate Test inventory generation failed; shard execution was not started" "${WORKFLOW}" >/dev/null || { printf 'FAIL: candidate inventory producer is not terminal\n'; exit 1; }
grep -F "::error::Test inventory generation failed for" "${WORKFLOW}" >/dev/null || { printf 'FAIL: Test does not emit the inventory generation diagnostic\n'; exit 1; }
if grep -A4 'name: Report candidate Test inventory generation failure' "${WORKFLOW}" | grep -F 'homeboy-test-shard-plan-' >/dev/null; then
  printf 'FAIL: inventory failure reporting attempts to read a missing shard plan artifact\n'; exit 1
fi
selection_dir="${tmp}/candidate-selection"
mkdir -p "${selection_dir}"
printf '%s\n' '{"review test":"pass"}' > "${selection_dir}/results.json"
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{"test_counts":{"passed":1,"failed":0,"skipped":0,"total":1}}}' > "${selection_dir}/review-test.json"
select_candidate_baseline() {
  GITHUB_ACTION_PATH="${ROOT}" GITHUB_OUTPUT="${selection_dir}/output" TEST_SHARD_COMMAND='review test' TEST_SHARD_RESULTS_FILE="${selection_dir}/results.json" TEST_SHARD_RESULT_FILE="${selection_dir}/review-test.json" TEST_SHARD_EVENT="$1" TEST_SHARD_DIFFERENTIAL_GATING="$2" TEST_SHARD_BASELINE_COMMANDS="$3" bash "${ROOT}/scripts/core/select-test-baseline.sh"
}
select_candidate_baseline pull_request true auto
grep -Fx 'baseline-command=' "${selection_dir}/output" >/dev/null || { printf 'FAIL: passing candidate shard result selected a baseline\n'; exit 1; }
printf '%s\n' '{"review test":"fail"}' > "${selection_dir}/results.json"
: > "${selection_dir}/output"
select_candidate_baseline pull_request true auto
grep -Fx 'baseline-command=review test' "${selection_dir}/output" >/dev/null || { printf 'FAIL: PR differential candidate failure did not select its matching baseline command\n'; exit 1; }
: > "${selection_dir}/output"
env -u GITHUB_ACTION_PATH GITHUB_OUTPUT="${selection_dir}/output" TEST_SHARD_COMMAND='review test' TEST_SHARD_RESULTS_FILE="${selection_dir}/results.json" TEST_SHARD_RESULT_FILE="${selection_dir}/review-test.json" TEST_SHARD_EVENT=pull_request TEST_SHARD_DIFFERENTIAL_GATING=true TEST_SHARD_BASELINE_COMMANDS=auto bash "${ROOT}/scripts/core/select-test-baseline.sh"
grep -Fx 'baseline-command=review test' "${selection_dir}/output" >/dev/null || { printf 'FAIL: direct baseline selection without GITHUB_ACTION_PATH did not resolve core lib\n'; exit 1; }
printf '%s\n' '{"review test":"timeout"}' > "${selection_dir}/results.json"
: > "${selection_dir}/output"
select_candidate_baseline pull_request true 'review test'
grep -Fx 'baseline-command=review test' "${selection_dir}/output" >/dev/null || { printf 'FAIL: timed-out candidate shard result did not select its matching baseline command\n'; exit 1; }
for policy in 'pr-baseline-none:true:none' 'pr-baseline-lint:true:review lint' 'pr-baseline-test:true:review test' 'pr-baseline-mixed:true:review lint, review test' 'pr-differential-false:false:auto' 'non-pr:true:review test'; do
  IFS=: read -r policy_name differential baseline_commands <<< "${policy}"
  : > "${selection_dir}/output"
  event=pull_request
  [ "${policy_name}" = non-pr ] && event=push
  select_candidate_baseline "${event}" "${differential}" "${baseline_commands}"
  case "${policy_name}" in
    pr-baseline-test|pr-baseline-mixed) grep -Fx 'baseline-command=review test' "${selection_dir}/output" >/dev/null || { printf 'FAIL: %s did not select review test\n' "${policy_name}"; exit 1; }; continue ;;
  esac
  grep -Fx 'baseline-command=' "${selection_dir}/output" >/dev/null || { printf 'FAIL: %s candidate failure selected a baseline\n' "${policy_name}"; exit 1; }
  if RESULTS="$(cat "${selection_dir}/results.json")" COMMANDS='review test' OPERATIONS_RESULTS='' PR_ACTIVE='' bash "${ROOT}/scripts/core/enforce-final-status.sh" >/dev/null 2>&1; then
    printf 'FAIL: %s candidate failure was not directly enforced\n' "${policy_name}"; exit 1
  fi
done
rm "${selection_dir}/review-test.json"
if select_candidate_baseline pull_request true 'review test' >/dev/null 2>&1; then
  printf 'FAIL: missing canonical candidate shard result selected a baseline\n'; exit 1
fi
# shellcheck disable=SC2016
grep -F 'resolve-run-artifact.sh homeboy-candidate-test-results' "${WORKFLOW}" >/dev/null || { printf 'FAIL: final Test does not resolve a prior successful aggregate result on rerun\n'; exit 1; }
# shellcheck disable=SC2016
# shellcheck disable=SC2016
grep -F 'name: ${{ steps.candidate-test-result-artifact.outputs.artifact-name }}' "${WORKFLOW}" >/dev/null || { printf 'FAIL: final Test does not consume the resolved aggregate result\n'; exit 1; }
# shellcheck disable=SC2016
if grep -A15 'name: Resolve candidate Test result' "${WORKFLOW}" | grep -F 'homeboy-candidate-test-results-${{ github.run_attempt }}' >/dev/null; then
  printf 'FAIL: final Test still requires a same-attempt aggregate artifact\n'
  exit 1
fi
printf 'PASS: canonical candidate result validation fails closed when the aggregate is absent\n'
printf 'PASS: policy waits for sharded Test reconciliation while preserving unsharded behavior\n'
