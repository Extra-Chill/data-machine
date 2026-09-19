#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

BIN_DIR="${TMPDIR}/bin"
OUTPUT_DIR="${TMPDIR}/output"
SUMMARY_FILE="${TMPDIR}/summary.md"
ENV_FILE="${TMPDIR}/github-env"
ARGS_FILE="${TMPDIR}/homeboy-args"
CALLS_FILE="${TMPDIR}/homeboy-calls"
mkdir -p "${BIN_DIR}" "${OUTPUT_DIR}"
mkdir -p "${TMPDIR}/observations"

cat > "${TMPDIR}/observations/runs.json" <<'JSON'
[
  {
    "kind": "test",
    "status": "error",
    "metadata_json": {
      "error": "Invalid argument 'structured_sidecar': structured sidecar `test.failures` must be a JSON array for schema v1"
    }
  }
]
JSON

cat > "${BIN_DIR}/homeboy" <<'STUB'
#!/usr/bin/env bash
if [ "$1" != "runs" ] || [ "$2" != "report" ] || [ "$3" != "failure-digest" ]; then
  printf 'unsupported Homeboy command: %s\n' "$*" >&2
  exit 2
fi
printf '%s\n' "$*" >> "${HOMEBOY_STUB_CALLS_FILE}"
if [ "$4" = "--help" ]; then
  printf 'Render failure digest\n'
  exit 0
fi
printf '%s\n' "$@" > "${HOMEBOY_STUB_ARGS_FILE}"
cat <<'MARKDOWN'
## Failure Digest

### Test Failure Digest
- Failed tests: **1**
MARKDOWN
STUB
chmod +x "${BIN_DIR}/homeboy"

export PATH="${BIN_DIR}:${PATH}"
export HOMEBOY_STUB_ARGS_FILE="${ARGS_FILE}"
export HOMEBOY_STUB_CALLS_FILE="${CALLS_FILE}"
export HOMEBOY_OUTPUT_DIR="${OUTPUT_DIR}"
export RESULTS='{"review lint":"fail","review test":"fail"}'
export GITHUB_SERVER_URL="https://github.com"
export GITHUB_REPOSITORY="Extra-Chill/homeboy-action"
export GITHUB_RUN_ID="12345"
export GITHUB_REPOSITORY="Extra-Chill/homeboy-action"
export HOMEBOY_CLI_VERSION="homeboy 1.2.3"
export HOMEBOY_EXTENSION_ID="nodejs"
export HOMEBOY_EXTENSION_SOURCE="https://github.com/Extra-Chill/homeboy-extensions"
export HOMEBOY_EXTENSION_REVISION="abc123"
export HOMEBOY_ACTION_REPOSITORY="Extra-Chill/homeboy-action"
export HOMEBOY_ACTION_REF="v2"
export COMMANDS="review lint,review test,review audit"
export COMPONENT_NAME="wordpress"
export SCOPE_CONTEXT="pr"
export SCOPE_MODE="changed"
export SCOPE_BASE_REF="abc123base"
export GITHUB_ENV="${ENV_FILE}"
export GITHUB_STEP_SUMMARY="${SUMMARY_FILE}"
export HOMEBOY_OBSERVATIONS_DIR="${TMPDIR}/observations"
export GITHUB_ACTION_PATH="${ROOT}"

bash "${ROOT}/scripts/digest/generate-failure-digest.sh"

DIGEST_FILE="${OUTPUT_DIR}/failure-digest.md"
TOOLING_JSON="${OUTPUT_DIR}/failure-digest-tooling.json"

if [ ! -f "${DIGEST_FILE}" ]; then
  echo "missing digest file" >&2
  exit 1
fi

grep -F "HOMEBOY_FAILURE_DIGEST_FILE=${DIGEST_FILE}" "${ENV_FILE}" >/dev/null
grep -F "### Test Failure Digest" "${SUMMARY_FILE}" >/dev/null
grep -F "### Local reproduction" "${DIGEST_FILE}" >/dev/null
grep -F -- "- Scope context: \`pr\`" "${DIGEST_FILE}" >/dev/null
grep -F -- "- Scope mode: \`changed\`" "${DIGEST_FILE}" >/dev/null
grep -F -- "- Scope base ref: \`abc123base\`" "${DIGEST_FILE}" >/dev/null
grep -F "homeboy review lint wordpress --path . --changed-since abc123base" "${DIGEST_FILE}" >/dev/null
grep -F "homeboy review test wordpress --path . --changed-since abc123base" "${DIGEST_FILE}" >/dev/null
grep -F "### Terminal diagnostics" "${DIGEST_FILE}" >/dev/null
grep -F "structured sidecar \`test.failures\` must be a JSON array for schema v1" "${DIGEST_FILE}" >/dev/null

grep -Fx -- "runs" "${ARGS_FILE}" >/dev/null
grep -Fx -- "report" "${ARGS_FILE}" >/dev/null
grep -Fx -- "failure-digest" "${ARGS_FILE}" >/dev/null
grep -Fx -- "--output-dir" "${ARGS_FILE}" >/dev/null
grep -Fx -- "${OUTPUT_DIR}" "${ARGS_FILE}" >/dev/null
grep -Fx -- "--results" "${ARGS_FILE}" >/dev/null
grep -Fx -- "${RESULTS}" "${ARGS_FILE}" >/dev/null
grep -Fx -- "--run-url" "${ARGS_FILE}" >/dev/null
grep -Fx -- "https://github.com/Extra-Chill/homeboy-action/actions/runs/12345" "${ARGS_FILE}" >/dev/null
grep -Fx -- "--tooling-json" "${ARGS_FILE}" >/dev/null
grep -Fx -- "${TOOLING_JSON}" "${ARGS_FILE}" >/dev/null
grep -Fx -- "--commands" "${ARGS_FILE}" >/dev/null
grep -Fx -- "review lint,review test,review audit" "${ARGS_FILE}" >/dev/null
grep -F "runs report failure-digest --help" "${CALLS_FILE}" >/dev/null

jq -e \
  '.homeboy_cli_version == "homeboy 1.2.3" and
   .extension_id == "nodejs" and
   .extension_source == "https://github.com/Extra-Chill/homeboy-extensions" and
   .extension_revision == "abc123" and
   .action_repository == "Extra-Chill/homeboy-action" and
   .action_ref == "v2"' \
  "${TOOLING_JSON}" >/dev/null

export RESULTS='{"review test":"baseline_red"}'
cat > "${OUTPUT_DIR}/baseline-status.json" <<'JSON'
{
  "review test": {
    "status": "fail",
    "exit_code": 1,
    "command": "homeboy review test wordpress --path /work --changed-since abc123base",
    "structured_output": false
  }
}
JSON

bash "${ROOT}/scripts/digest/generate-failure-digest.sh"

grep -F "### Differential baseline evidence" "${DIGEST_FILE}" >/dev/null
grep -F -- '- `review test`: **baseline_red**' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Baseline command: `homeboy review test wordpress --path /work --changed-since abc123base`' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Baseline result: `fail` (exit `1`)' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Candidate result: `baseline_red`' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Artifact refs: `review-test.json`, `baseline-review-test.json`, `baseline-review-test.log`' "${DIGEST_FILE}" >/dev/null

cp "${ROOT}/scripts/pr/comment/fixtures/timeout-recipe-byte-map.json" "${OUTPUT_DIR}/review-test.json"
export RESULTS='{"review test":"timeout"}'
export GITHUB_RUN_URL="https://github.com/Extra-Chill/homeboy-action/actions/runs/12345"
export GITHUB_PR_URL="https://github.com/Extra-Chill/data-machine/pull/3192"
export HOMEBOY_CI_RESULTS_ARTIFACT="homeboy-ci-results-timeout"
export HOMEBOY_OBSERVATIONS_ARTIFACT="homeboy-observations-timeout"
export HOMEBOY_TEST_TIMEOUT_SECONDS=1500

bash "${ROOT}/scripts/digest/generate-failure-digest.sh"

grep -F "### Timeout triage" "${DIGEST_FILE}" >/dev/null
grep -F 'elapsed/budget: `1500s / 1500s`' "${DIGEST_FILE}" >/dev/null
grep -F 'Selected: `282`; counts: incomplete (test counts absent)' "${DIGEST_FILE}" >/dev/null
grep -F 'homeboy review ci triage https://github.com/Extra-Chill/data-machine/pull/3192' "${DIGEST_FILE}" >/dev/null
grep -F "homeboy runs import --from-gh-actions --component wordpress --repo Extra-Chill/homeboy-action --artifact-glob 'homeboy-observations-timeout' --run-id 12345" "${DIGEST_FILE}" >/dev/null
if grep -Fq 'top-secret-token\|do-not-display\|ghp_abcdefghijklmnopqrstuvwxyz0123456789' "${DIGEST_FILE}"; then
  echo "timeout digest exposed a secret or byte-map payload" >&2
  exit 1
fi

cat > "${BIN_DIR}/homeboy" <<'STUB'
#!/usr/bin/env bash
if [ "$4" = "--help" ]; then
  printf 'Render failure digest\n'
  exit 0
fi
printf 'renderer failed before producing markdown\n' >&2
exit 1
STUB
chmod +x "${BIN_DIR}/homeboy"
export RESULTS='{"review lint":"fail","review test":"timeout"}'

bash "${ROOT}/scripts/digest/generate-failure-digest.sh"

grep -F "## Failure digest unavailable" "${DIGEST_FILE}" >/dev/null
grep -F '`homeboy review lint`: **fail** - no summary recorded (result: `review-lint.json`)' "${DIGEST_FILE}" >/dev/null
grep -F '`homeboy review test`: **timeout** - no summary recorded (result: `review-test.json`)' "${DIGEST_FILE}" >/dev/null
grep -F "renderer failed before producing markdown" "${DIGEST_FILE}" >/dev/null
grep -F -- "- Status: **failed**" "${DIGEST_FILE}" >/dev/null
grep -F "HOMEBOY_FAILURE_DIGEST_FILE=${DIGEST_FILE}" "${ENV_FILE}" >/dev/null

cat > "${BIN_DIR}/homeboy" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HOMEBOY_STUB_CALLS_FILE}"
printf "error: unrecognized subcommand 'report'\n" >&2
exit 2
STUB
chmod +x "${BIN_DIR}/homeboy"
export RESULTS='{"review lint":"fail"}'
export HOMEBOY_CI_RESULTS_ARTIFACT="homeboy-ci-results-lint"
printf '%s\n' '{"summary":"26 lint finding(s) detected"}' > "${OUTPUT_DIR}/review-lint.json"
: > "${CALLS_FILE}"

bash "${ROOT}/scripts/digest/generate-failure-digest.sh"

grep -F "unsupported by selected Homeboy binary" "${DIGEST_FILE}" >/dev/null
grep -F "homeboy-ci-results-lint" "${DIGEST_FILE}" >/dev/null
grep -F "unrecognized subcommand 'report'" "${DIGEST_FILE}" >/dev/null
grep -F '`homeboy review lint`: **fail** - 26 lint finding(s) detected (result: `review-lint.json`)' "${DIGEST_FILE}" >/dev/null
grep -Fx "runs report failure-digest --help" "${CALLS_FILE}" >/dev/null
if grep -Fq -- "--output-dir" "${CALLS_FILE}"; then
  echo "unsupported renderer was invoked after its capability probe failed" >&2
  exit 1
fi

cat > "${OUTPUT_DIR}/setup.json" <<'JSON'
{"schema":"homeboy/action-setup-result/v1","phase":"dependency_build_setup","status":"failed","owner":"Homeboy extension setup","step":"install Homeboy extension","exit_code":1,"replay_command":"bash install-extension.sh","diagnostic":"source SHA mismatch"}
JSON
export HOMEBOY_SETUP_RESULT_FILE="${OUTPUT_DIR}/setup.json"
export RESULTS='{"setup":"fail","review lint":"not_run","review test":"not_run"}'
bash "${ROOT}/scripts/digest/generate-failure-digest.sh"
grep -F '## Setup failure digest' "${DIGEST_FILE}" >/dev/null
grep -F 'The requested quality commands were not run.' "${DIGEST_FILE}" >/dev/null
grep -F '### Setup failure' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Owner: **Homeboy extension setup**' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Diagnostic: source SHA mismatch' "${DIGEST_FILE}" >/dev/null
grep -F -- '- Replay: `bash install-extension.sh`' "${DIGEST_FILE}" >/dev/null

echo "generate failure digest wrapper checks passed"
