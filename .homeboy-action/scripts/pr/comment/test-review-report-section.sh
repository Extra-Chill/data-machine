#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL: %s\nmissing: %s\nbody:\n%s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_not_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"

  if [[ "${haystack}" == *"${needle}"* ]]; then
    printf 'FAIL: %s\nunexpected: %s\nbody:\n%s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

fake_bin="$(mktemp -d)"
trap 'rm -rf "${fake_bin}"' EXIT

cat > "${fake_bin}/homeboy" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HOMEBOY_REVIEW_CALL_LOG}"
if [ "${HOMEBOY_REVIEW_OUTPUT_MODE:-}" = "custom" ]; then
  printf 'Custom core-rendered markdown without action-side shape coupling\n'
  exit 0
fi
printf ':zap: Scope: **changed files only** (since `origin/main`)\n\n'
printf '> :warning: **binary-source:** fallback release binary (source build failed)\n\n'
printf '**2** finding(s) across 2 stage(s)\n\n'
printf ':x: **audit** — failed (2 finding(s))\n'
exit 1
SH
chmod +x "${fake_bin}/homeboy"

export PATH="${fake_bin}:${PATH}"
export HOMEBOY_REVIEW_CALL_LOG="${fake_bin}/review.log"
export GITHUB_ACTION_PATH="${ROOT}"
export COMMANDS="review audit,review lint,review test"
export RESULTS='{"review audit":"fail","review lint":"pass","review test":"pass"}'
export COMP_ID="data-machine"
export WORKSPACE="/tmp/workspace"
export SECTION_TITLE="Audit"
export BINARY_SOURCE="fallback"
export HOMEBOY_CI_RESULTS_ARTIFACT="homeboy-ci-results-data-machine-audit"
export HOMEBOY_OBSERVATIONS_ARTIFACT="homeboy-observations-data-machine-audit"
export GITHUB_RUN_URL="https://github.com/Extra-Chill/homeboy-action/actions/runs/123"
export GITHUB_PR_URL="https://github.com/Extra-Chill/data-machine/pull/3192"
export GITHUB_REPOSITORY="Extra-Chill/homeboy-action"
export GITHUB_RUN_ID="123"
export GITHUB_ACTIONS="true"
export HOMEBOY_ACTION_PLACEMENT_MODE="global"
export SCOPE_MODE="changed"
export SCOPE_BASE_REF="origin/main"
export DIGEST_FILE=""

source "${ROOT}/scripts/core/lib.sh"
source "${ROOT}/scripts/pr/comment/sections.sh"

build_section_body

assert_contains "${SECTION_BODY}" "### Audit" "section title preserved"
assert_contains "${SECTION_BODY}" "**2** finding(s) across 2 stage(s)" "review markdown appended"
assert_contains "${SECTION_BODY}" ":x: **audit** — failed" "review stage markdown appended"
assert_contains "${SECTION_BODY}" "CI results artifact: \`homeboy-ci-results-data-machine-audit\`" "comment distinguishes CI result artifact"
assert_contains "${SECTION_BODY}" "Observation artifact: \`homeboy-observations-data-machine-audit\`" "comment links observation artifact guidance"
assert_contains "${SECTION_BODY}" "homeboy runs import <dir>" "comment includes observation import command"
assert_contains "${SECTION_BODY}" "homeboy runs findings <run-id>" "comment includes findings drill-down command"
assert_contains "${SECTION_BODY}" "https://github.com/Extra-Chill/homeboy-action/actions/runs/123" "comment links workflow run artifacts"
assert_not_contains "${SECTION_BODY}" ":x: **audit** _(changed files only)_" "legacy per-command audit block skipped"
assert_contains "$(cat "${HOMEBOY_REVIEW_CALL_LOG}")" "--banner binary-source=fallback release binary (source build failed)" "binary-source banner passed to core"
assert_contains "$(cat "${HOMEBOY_REVIEW_CALL_LOG}")" "--placement local review data-machine" "review report opts into local placement in GitHub Actions"

cat > "${fake_bin}/setup.json" <<'JSON'
{"schema":"homeboy/action-setup-result/v1","phase":"dependency_build_setup","status":"failed","owner":"Homeboy extension setup","step":"install Homeboy extension","exit_code":1,"replay_command":"bash install-extension.sh","diagnostic":"source SHA mismatch"}
JSON
export HOMEBOY_SETUP_RESULT_FILE="${fake_bin}/setup.json"
export RESULTS='{"setup":"fail","review audit":"not_run","review lint":"not_run","review test":"not_run"}'
: > "${HOMEBOY_REVIEW_CALL_LOG}"
build_section_body
assert_contains "${SECTION_BODY}" "Setup failed - Homeboy extension setup" "setup-owned failure is prominent in PR summary"
assert_contains "${SECTION_BODY}" "Diagnostic: source SHA mismatch" "PR summary preserves setup cause"
assert_contains "${SECTION_BODY}" 'Replay: `bash install-extension.sh`' "PR summary includes setup replay command"
assert_contains "${SECTION_BODY}" ":fast_forward: **review lint** — not run (setup failed)" "PR summary marks quality commands not run"
assert_not_contains "$(cat "${HOMEBOY_REVIEW_CALL_LOG}")" "review --report=pr-comment" "setup failure does not invoke a command-result renderer"
unset HOMEBOY_SETUP_RESULT_FILE
export RESULTS='{"review audit":"fail","review lint":"pass","review test":"pass"}'

export HOMEBOY_REVIEW_OUTPUT_MODE="custom"
build_section_body
assert_contains "${SECTION_BODY}" "Custom core-rendered markdown without action-side shape coupling" "custom core markdown is appended without report-shape checks"
unset HOMEBOY_REVIEW_OUTPUT_MODE

export EXTRA_ARGS="--format json"
build_section_body

assert_contains "${SECTION_BODY}" ":x: **review audit** — failed" "custom args render split audit status"
assert_contains "${SECTION_BODY}" ":white_check_mark: **review lint** — passed" "custom args render split lint status"
assert_contains "${SECTION_BODY}" ":white_check_mark: **review test** — passed" "custom args render split test status"
assert_not_contains "${SECTION_BODY}" ":x: **audit** _(changed files only)_" "custom args do not fall back to legacy command blocks"
assert_not_contains "${SECTION_BODY}" "core PR-comment rendering currently supports the default" "custom args do not use unsupported warning"

split_output_dir="$(mktemp -d)"
cat > "${split_output_dir}/review-lint.json" <<'JSON'
{
  "success": false,
  "data": {
    "hints": ["Some issues may require manual fixes"],
    "lint_findings": [
      {"category":"phpstan","message":"A"},
      {"category":"phpstan","message":"B"},
      {"category":"phpcs","message":"C"}
    ]
  }
}
JSON

export COMMANDS="review lint"
export RESULTS='{"review lint":"fail"}'
export SECTION_TITLE="Lint"
export OUTPUT_DIR="${split_output_dir}"
unset EXTRA_ARGS
build_section_body

assert_contains "${SECTION_BODY}" "### Lint" "split command section title preserved"
assert_contains "${SECTION_BODY}" ":x: **review lint** — failed" "split command failure rendered"
assert_contains "${SECTION_BODY}" '`phpstan` — 2 finding(s)' "split command phpstan bucket rendered"
assert_contains "${SECTION_BODY}" '`phpcs` — 1 finding(s)' "split command phpcs bucket rendered"
assert_contains "${SECTION_BODY}" "_Total: 3 finding(s)_" "split command total rendered"
assert_contains "${SECTION_BODY}" "Some issues may require manual fixes" "split command hints rendered"
assert_contains "${SECTION_BODY}" "Deep dive: homeboy review lint data-machine --changed-since origin/main" "split command deep dive rendered"
assert_not_contains "${SECTION_BODY}" "core PR-comment rendering currently supports the default" "split command does not use unsupported warning"

timeout_output_dir="$(mktemp -d)"
trap 'rm -rf "${fake_bin}" "${split_output_dir}" "${timeout_output_dir}"' EXIT
cp "${ROOT}/scripts/pr/comment/fixtures/timeout-recipe-byte-map.json" "${timeout_output_dir}/review-test.json"

export COMMANDS="review test"
export RESULTS='{"review test":"timeout"}'
export SECTION_TITLE="Test"
export OUTPUT_DIR="${timeout_output_dir}"
export HOMEBOY_TEST_TIMEOUT_SECONDS=1500
export HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1800
build_section_body

assert_contains "${SECTION_BODY}" "#### Timeout triage" "timeout has a self-contained triage block"
assert_contains "${SECTION_BODY}" "Phase: \`command_execution\`; elapsed/budget: \`1500s / 1500s\`" "timeout projects phase and budget"
assert_contains "${SECTION_BODY}" "Selected: \`282\`; counts: incomplete (test counts absent)" "timeout projects selected count and absent counts"
assert_contains "${SECTION_BODY}" "Recipe test selection did not finish before the budget; authorization: [REDACTED]" "timeout projects redacted semantic diagnostic"
assert_contains "${SECTION_BODY}" "[CI results artifact: \`homeboy-ci-results-data-machine-audit\`](https://github.com/Extra-Chill/homeboy-action/actions/runs/123)" "timeout links CI artifact to workflow run"
assert_contains "${SECTION_BODY}" "[Observation artifact: \`homeboy-observations-data-machine-audit\`](https://github.com/Extra-Chill/homeboy-action/actions/runs/123)" "timeout links observation artifact to workflow run"
assert_contains "${SECTION_BODY}" "homeboy review ci triage https://github.com/Extra-Chill/data-machine/pull/3192" "timeout includes exact PR triage command"
assert_contains "${SECTION_BODY}" "homeboy runs import --from-gh-actions --component data-machine --repo Extra-Chill/homeboy-action --artifact-glob 'homeboy-observations-data-machine-audit' --run-id 123" "timeout includes exact GitHub Actions import command"
assert_not_contains "${SECTION_BODY}" "top-secret-token" "timeout redacts bearer tokens"
assert_not_contains "${SECTION_BODY}" "do-not-display" "timeout redacts key-value secrets"
assert_not_contains "${SECTION_BODY}" "ghp_abcdefghijklmnopqrstuvwxyz0123456789" "timeout does not render recipe byte maps"
assert_not_contains "${SECTION_BODY}" "unbounded raw transport data" "timeout diagnostic remains semantic and bounded"

jq 'del(.data.timeout.budget_seconds)' "${ROOT}/scripts/pr/comment/fixtures/timeout-recipe-byte-map.json" > "${timeout_output_dir}/review-test.json"
build_section_body
assert_contains "${SECTION_BODY}" 'elapsed/budget: `1500s / 1500s`' "test timeout falls back to the test budget"

cp "${timeout_output_dir}/review-test.json" "${timeout_output_dir}/review-audit.json"
export COMMANDS="review audit"
export RESULTS='{"review audit":"timeout"}'
export SECTION_TITLE="Audit"
build_section_body
assert_contains "${SECTION_BODY}" 'elapsed/budget: `1500s / 1800s`' "audit timeout falls back to the execution budget"

cp "${timeout_output_dir}/review-test.json" "${timeout_output_dir}/review-lint.json"
export COMMANDS="review lint"
export RESULTS='{"review lint":"timeout"}'
export SECTION_TITLE="Lint"
build_section_body
assert_contains "${SECTION_BODY}" 'elapsed/budget: `1500s / 1800s`' "lint timeout falls back to the execution budget"

export COMMANDS="review test"
export RESULTS='{"review test":"timeout"}'
export SECTION_TITLE="Test"

cp "${ROOT}/scripts/pr/comment/fixtures/timeout-secret-diagnostics.json" "${timeout_output_dir}/review-test.json"
build_section_body

assert_contains "${SECTION_BODY}" "authorization: [REDACTED]" "timeout sanitizes raw authorization headers"
assert_not_contains "${SECTION_BODY}" "YWxpY2U6c2VjcmV0" "timeout redacts Basic credentials"
assert_not_contains "${SECTION_BODY}" "session=super-secret" "timeout redacts Cookie headers"
assert_not_contains "${SECTION_BODY}" "refresh=also-secret" "timeout redacts Set-Cookie headers"
assert_not_contains "${SECTION_BODY}" "alice:password" "timeout redacts URL credentials"
assert_not_contains "${SECTION_BODY}" "from-environment" "timeout redacts environment-derived secrets"
assert_not_contains "${SECTION_BODY}" "multiline-secret" "timeout redacts multiline key-value secrets"
assert_contains "${SECTION_BODY}" "--token [REDACTED] --password [REDACTED] --secret [REDACTED]" "timeout preserves CLI secret flags while redacting values"
assert_not_contains "${SECTION_BODY}" "raw-token" "timeout redacts separated token flag values"
assert_not_contains "${SECTION_BODY}" "raw password" "timeout redacts quoted password flag values"
assert_not_contains "${SECTION_BODY}" "raw-secret" "timeout redacts assigned secret flag values"
assert_not_contains "${SECTION_BODY}" "raw-api" "timeout redacts API key flag values"
assert_not_contains "${SECTION_BODY}" "raw-private" "timeout redacts private key flag values"
assert_not_contains "${SECTION_BODY}" "raw-credential" "timeout redacts credential flag values"
assert_not_contains "${SECTION_BODY}" "raw-short-token" "timeout redacts short token flag values"
assert_not_contains "${SECTION_BODY}" "raw-short-key" "timeout redacts short key flag values"

huge_diagnostic="$(printf 'A%.0s' {1..20000})"
jq -n --arg huge "${huge_diagnostic}" '{schema:"homeboy/command-result/v3",data:{timeout:{phase:"bad`\n# injected",elapsed_seconds:"1`\n# injected",budget_seconds:1500},selection:{selected_count:"2`\n# injected"},raw_output:{stderr_tail:("-----BEGIN RSA PRIVATE KEY-----\nprivate-material\n-----END RSA PRIVATE KEY-----\n" + $huge)}}}' > "${timeout_output_dir}/review-test.json"
build_section_body

assert_contains "${SECTION_BODY}" 'Phase: `unknown`; elapsed/budget: `unknowns / 1500s`' "timeout normalizes injected phase and timing values"
assert_contains "${SECTION_BODY}" 'Selected: `unknown`' "timeout normalizes injected selected count"
assert_not_contains "${SECTION_BODY}" 'private-material' "timeout redacts PEM private key blocks"
assert_not_contains "${SECTION_BODY}" 'bad`' "timeout prevents structured Markdown injection"
timeout_diagnostic_line="$(printf '%s\n' "${SECTION_BODY}" | grep '^> ' || true)"
timeout_diagnostic_line="${timeout_diagnostic_line%%$'\n'*}"
[ "${#timeout_diagnostic_line}" -le 482 ] || { printf 'FAIL: timeout diagnostic exceeds the visible bound\n'; exit 1; }
printf 'PASS: timeout bounds huge diagnostics\n'

import_command="$(timeout_import_command)"
read -r -a import_args <<< "${import_command}"
[ "${#import_args[@]}" -eq 12 ] || { printf 'FAIL: timeout import command did not parse into required arguments\n'; exit 1; }
[ "${import_args[3]}" = '--from-gh-actions' ] && [ "${import_args[4]}" = '--component' ] && [ "${import_args[6]}" = '--repo' ] && [ "${import_args[8]}" = '--artifact-glob' ] && [ "${import_args[10]}" = '--run-id' ] || { printf 'FAIL: timeout import command is missing required CLI flags\n'; exit 1; }
printf 'PASS: timeout import command is shell-parseable with all required CLI flags\n'

printf 'All review report section checks passed.\n'
