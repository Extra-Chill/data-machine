#!/usr/bin/env bash

# Verify a failed lint envelope remains available to every downstream consumer.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin" "${TMP_DIR}/workspace"
cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

case "$*" in
  *"runs report failure-digest"*)
    printf '%s\n' '## Failure Digest'
    ;;
  *"runs findings reconcile-run"*)
    printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"runs","operation":"findings reconcile-run","success":true,"exit_code":0,"status":"succeeded","data":{"variant":"findings_reconcile_run","payload":{"commands":[{"command":"lint","component_id":"example","source":"'"${HOMEBOY_CI_RESULTS_DIR}"'/review-lint.json","status":"processed","warnings":[],"issues_created":1,"issues_updated":0,"issues_closed":0,"failures":0,"reconcile":{"plan_lines":["file lint finding"],"result":{"executions":[{"outcome":{"outcome":"filed"}}]}}}],"totals":{"commands_processed":1,"issues_created":1,"issues_updated":0,"issues_closed":0,"failures":0}}}}'
    ;;
  *"review lint"*)
    while [ "$#" -gt 0 ]; do
      case "$1" in
        --output) output="$2"; shift 2 ;;
        *) shift ;;
      esac
    done
    mkdir -p "$(dirname "${output}")"
    printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":false,"status":"failed","exit_code":1,"data":{"component_id":"example","findings":[{"category":"style","message":"lint failure"}]}}' > "${output}"
    exit 1
    ;;
  *)
    printf 'unexpected homeboy invocation: %s\n' "$*" >&2
    exit 99
    ;;
esac
SH
chmod +x "${TMP_DIR}/bin/homeboy"

set +e
PATH="${TMP_DIR}/bin:${PATH}" \
GITHUB_ACTION_PATH="${ROOT_DIR}" \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_OUTPUT="${TMP_DIR}/run-output" \
GITHUB_ENV="${TMP_DIR}/github-env" \
RESOLVED_COMMANDS='review lint' \
COMPONENT_NAME='example' \
bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh" > "${TMP_DIR}/run.log" 2>&1
status=$?
set -e

[ "${status}" -eq 1 ] || { printf 'FAIL: failed review lint exits 1\n'; exit 1; }
grep -qx 'results={"review lint":"fail"}' "${TMP_DIR}/run-output"
RESULTS_DIR="$(grep '^HOMEBOY_CI_RESULTS_DIR=' "${TMP_DIR}/github-env" | cut -d= -f2-)"
[ -s "${RESULTS_DIR}/review-lint.json" ] || { printf 'FAIL: lint result was not written to the durable result path\n'; exit 1; }
jq -e '.schema == "homeboy/command-result/v3" and (.data.findings | length) == 1' "${RESULTS_DIR}/review-lint.json" >/dev/null

FIRST_RESULTS='{"review lint":"fail"}' \
COMMANDS='review lint' \
HOMEBOY_CI_RESULTS_DIR="${RESULTS_DIR}" \
GITHUB_OUTPUT="${TMP_DIR}/selected-output" \
bash "${ROOT_DIR}/scripts/core/select-final-results.sh"
grep -F '"review lint":"fail"' "${TMP_DIR}/selected-output" >/dev/null

PATH="${TMP_DIR}/bin:${PATH}" \
HOMEBOY_CI_RESULTS_DIR="${RESULTS_DIR}" \
RESULTS='{"review lint":"fail"}' \
COMMANDS='review lint' \
GITHUB_SERVER_URL='https://github.com' \
GITHUB_REPOSITORY='example-org/example' \
GITHUB_RUN_ID='1' \
bash "${ROOT_DIR}/scripts/digest/generate-failure-digest.sh"
[ -s "${RESULTS_DIR}/failure-digest.md" ] || { printf 'FAIL: digest did not use the durable result path\n'; exit 1; }

PATH="${TMP_DIR}/bin:${PATH}" \
HOMEBOY_CI_RESULTS_DIR="${RESULTS_DIR}" \
RESULTS='{"review lint":"fail"}' \
COMMANDS='review lint' \
COMPONENT_NAME='example' \
GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
GITHUB_SERVER_URL='https://github.com' \
GITHUB_REPOSITORY='example-org/example' \
GITHUB_RUN_ID='1' \
bash "${ROOT_DIR}/scripts/issues/auto-file-categorized-issues.sh" > "${TMP_DIR}/categorize.log" 2>&1
grep -F "Source: ${RESULTS_DIR}/review-lint.json" "${TMP_DIR}/categorize.log" >/dev/null
grep -F 'file lint finding' "${TMP_DIR}/categorize.log" >/dev/null
grep -F 'Issues created: 1' "${TMP_DIR}/categorize.log" >/dev/null
if grep -Fq 'without producing structured output' "${TMP_DIR}/categorize.log"; then
  printf 'FAIL: categorizer treated the failed lint envelope as missing\n'
  exit 1
fi

printf 'PASS: failed review lint result is shared by selection, digest, and categorization\n'
