#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN_RELEASE="${ROOT_DIR}/scripts/release/run-release.sh"
RUN_RELEASE_WITH_LIVENESS="${ROOT_DIR}/scripts/release/run-release-with-liveness.sh"

assert_contains() {
  local needle="$1"
  local haystack="$2"
  local label="$3"

  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL: %s\nmissing: %s\nin: %s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_not_contains() {
  local needle="$1"
  local haystack="$2"
  local label="$3"

  if [[ "${haystack}" == *"${needle}"* ]]; then
    printf 'FAIL: %s\nforbidden: %s\nin: %s\n' "${label}" "${needle}" "${haystack}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_output_line() {
  local expected="$1"
  local file_path="$2"
  local label="$3"

  if ! grep -Fxq "${expected}" "${file_path}"; then
    printf 'FAIL: %s\nmissing output: %s\nactual:\n' "${label}" "${expected}"
    cat "${file_path}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

setup_fixture() {
  TEST_DIR="$(mktemp -d)"
  BIN_DIR="${TEST_DIR}/bin"
  WORKSPACE="${TEST_DIR}/workspace"
  OUTPUT_FILE="${TEST_DIR}/github-output"
  HOMEBOY_ARGS_FILE="${TEST_DIR}/homeboy-args"
  HOMEBOY_AUTH_FILE="${TEST_DIR}/homeboy-auth"
  MOCK_GIT_LOG="${TEST_DIR}/git-log"
  mkdir -p "${BIN_DIR}" "${WORKSPACE}"

  cat > "${WORKSPACE}/homeboy.json" <<'JSON'
{"id":"mock-component"}
JSON

  cat > "${BIN_DIR}/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
# Track every git invocation so the test can assert what the wrapper does
# (and just as importantly, what it no longer does — see homeboy core PR
# #2368, which moved tip-sync into `homeboy release` and removed the
# wrapper's redundant `git pull --ff-only`).
if [ -n "${MOCK_GIT_LOG:-}" ]; then
  printf '%s\n' "$*" >> "${MOCK_GIT_LOG}"
fi
case "$1 $2" in
  "rev-parse --abbrev-ref")
    # Returns the literal string "HEAD" on a detached checkout, which is
    # exactly what `actions/checkout` with `ref: <sha>` produces.
    echo "${MOCK_BRANCH:-main}"
    ;;
  "rev-parse HEAD")
    # Commit identity of the detached HEAD. Empty models a checkout so broken
    # that even this fails.
    [ -n "${MOCK_HEAD_SHA:-}" ] || exit 1
    echo "${MOCK_HEAD_SHA}"
    ;;
  "rev-parse -q")
    # `git rev-parse -q --verify <ref>`. Empty MOCK_RELEASE_BRANCH_SHA models a
    # checkout with no ref for the release branch (e.g. a shallow clone), which
    # is the genuinely undeterminable case.
    [ -n "${MOCK_RELEASE_BRANCH_SHA:-}" ] || exit 1
    echo "${MOCK_RELEASE_BRANCH_SHA}"
    ;;
  "symbolic-ref --short")
    echo "origin/${MOCK_DEFAULT_BRANCH:-main}"
    ;;
  *)
    echo "unexpected git args: $*" >&2
    exit 2
    ;;
esac
SH

  cat > "${BIN_DIR}/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

# Feature-detect probe used by run-release.sh: `homeboy release --help`.
# HOMEBOY_SUPPORTS_CONFIRM_FLAG toggles whether this mock binary advertises
# the --i-know-ci-creates-the-github-release flag, emulating newer (guarded)
# vs older (pre-#6137) published homeboy release binaries.
if [ "$1" = "release" ] && [ "${2:-}" = "--help" ]; then
  echo "Usage: homeboy release [OPTIONS]"
  echo "      --no-github-release"
  if [ "${HOMEBOY_SUPPORTS_CONFIRM_FLAG:-true}" = "true" ]; then
    echo "      --i-know-ci-creates-the-github-release"
  fi
  exit 0
fi

if [ "$1" != "--output" ]; then
  echo "missing --output" >&2
  exit 2
fi

if [ -n "${HOMEBOY_MOCK_DELAY:-}" ]; then
  sleep "${HOMEBOY_MOCK_DELAY}"
fi

output_file="$2"
shift 2
if [ "$1" != "release" ]; then
  echo "expected release command" >&2
  exit 2
fi
shift

printf '%s\n' "$*" > "${HOMEBOY_ARGS_FILE}"

if [ -n "${GH_TOKEN:-}" ]; then
  if [ -z "${GIT_ASKPASS:-}" ] || [ ! -x "${GIT_ASKPASS}" ]; then
    echo "missing executable GIT_ASKPASS" >&2
    exit 2
  fi
  {
    "${GIT_ASKPASS}" 'Username for https://github.com'
    "${GIT_ASKPASS}" 'Password for https://x-access-token@github.com'
    printf '%s\n' "${GIT_TERMINAL_PROMPT:-}"
  } > "${HOMEBOY_AUTH_FILE}"
fi

case "${HOMEBOY_MOCK_SCENARIO}" in
  released)
    cat > "${output_file}" <<'JSON'
{"success":true,"data":{"command":"release","result":{"component_id":"mock-component","new_version":"2.1.0","tag":"v2.1.0","bump_type":"minor","releasable_commits":3}}}
JSON
    ;;
  skipped)
    cat > "${output_file}" <<'JSON'
{"success":true,"data":{"command":"release","result":{"component_id":"mock-component","skipped_reason":"no-releasable-commits","bump_type":"patch"}}}
JSON
    ;;
  failed)
    cat > "${output_file}" <<'JSON'
{"success":false,"error":{"message":"mock failure"}}
JSON
    exit 1
    ;;
  step-failed)
    # The v3 command-result envelope a release that RAN and then failed a step
    # emits: no `.error`, but a fully classified `.diagnostics` and executable
    # `.next_actions` (Extra-Chill/homeboy#10441).
    cat > "${output_file}" <<'JSON'
{"schema":"command-result/v3","command":"release","success":false,"exit_code":1,"status":"failed","summary":"Release step github.release (github.release) failed: gh-upload-failed","next_actions":[{"label":"attach the built artifacts to the release that already exists","command":"gh release upload 'v2.1.0' --clobber -R 'Extra-Chill/homeboy'","kind":"repair"}],"diagnostics":{"code":"command.failed","message":"Release step github.release (github.release) failed: gh-upload-failed — `gh release upload` failed for v2.1.0: gh api release metadata exited with status 1"},"data":{"command":"release","result":{"component_id":"mock-component"}}}
JSON
    exit 1
    ;;
  liveness-timeout)
    sleep 30 &
    echo $! > "${HOMEBOY_CHILD_PID_FILE}"
    wait
    ;;
  *)
    echo "unknown HOMEBOY_MOCK_SCENARIO=${HOMEBOY_MOCK_SCENARIO}" >&2
    exit 2
    ;;
esac
SH

  # MOCK_RELEASE_DRAFT_STATE drives the publish-state read the wrapper uses to
  # confirm a release actually shipped. Defaults to a published release.
  cat > "${BIN_DIR}/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [ "$*" = "release view v2.1.0 --repo Extra-Chill/homeboy-action --json isDraft --jq .isDraft" ]; then
  printf '%s\n' "${MOCK_RELEASE_DRAFT_STATE:-false}"
  exit 0
fi
echo "unexpected gh args: $*" >&2
exit 2
SH

  chmod +x "${BIN_DIR}/git" "${BIN_DIR}/homeboy" "${BIN_DIR}/gh"
}

run_wrapper() {
  PATH="${BIN_DIR}:${PATH}" \
  GITHUB_OUTPUT="${OUTPUT_FILE}" \
  GITHUB_WORKSPACE="${WORKSPACE}" \
  GITHUB_REPOSITORY="Extra-Chill/homeboy-action" \
  COMPONENT_NAME="${COMPONENT_NAME:-}" \
  PORTABLE_ID="${PORTABLE_ID:-}" \
  COMPONENT_DIR="${COMPONENT_DIR:-.}" \
  HOMEBOY_ARGS_FILE="${HOMEBOY_ARGS_FILE}" \
  HOMEBOY_AUTH_FILE="${HOMEBOY_AUTH_FILE}" \
  HOMEBOY_MOCK_SCENARIO="${HOMEBOY_MOCK_SCENARIO}" \
  HOMEBOY_SUPPORTS_CONFIRM_FLAG="${HOMEBOY_SUPPORTS_CONFIRM_FLAG:-true}" \
  MOCK_RELEASE_DRAFT_STATE="${MOCK_RELEASE_DRAFT_STATE:-false}" \
  MOCK_GIT_LOG="${MOCK_GIT_LOG}" \
  RELEASE_DRY_RUN="${RELEASE_DRY_RUN:-false}" \
  RELEASE_SKIP_PUBLISH="${RELEASE_SKIP_PUBLISH:-false}" \
  RELEASE_SKIP_GITHUB_RELEASE="${RELEASE_SKIP_GITHUB_RELEASE:-false}" \
  RELEASE_HEAD="${RELEASE_HEAD:-false}" \
  RELEASE_FROM_ARTIFACTS="${RELEASE_FROM_ARTIFACTS:-}" \
  MOCK_BRANCH="${MOCK_BRANCH:-main}" \
  MOCK_HEAD_SHA="${MOCK_HEAD_SHA:-}" \
  MOCK_RELEASE_BRANCH_SHA="${MOCK_RELEASE_BRANCH_SHA:-}" \
  GH_TOKEN="${GH_TOKEN:-}" \
  bash "${RUN_RELEASE}"
}

run_liveness_wrapper() {
  PATH="${BIN_DIR}:${PATH}" \
  GITHUB_OUTPUT="${OUTPUT_FILE}" \
  GITHUB_WORKSPACE="${WORKSPACE}" \
  GITHUB_REPOSITORY="Extra-Chill/homeboy-action" \
  HOMEBOY_ARGS_FILE="${HOMEBOY_ARGS_FILE}" \
  HOMEBOY_AUTH_FILE="${HOMEBOY_AUTH_FILE}" \
  HOMEBOY_MOCK_SCENARIO="${HOMEBOY_MOCK_SCENARIO}" \
  HOMEBOY_MOCK_DELAY="${HOMEBOY_MOCK_DELAY:-}" \
  HOMEBOY_CHILD_PID_FILE="${HOMEBOY_CHILD_PID_FILE:-}" \
  HOMEBOY_SUPPORTS_CONFIRM_FLAG="${HOMEBOY_SUPPORTS_CONFIRM_FLAG:-true}" \
  MOCK_RELEASE_DRAFT_STATE="${MOCK_RELEASE_DRAFT_STATE:-false}" \
  MOCK_GIT_LOG="${MOCK_GIT_LOG}" \
  RELEASE_DRY_RUN="${RELEASE_DRY_RUN:-false}" \
  RELEASE_SKIP_PUBLISH="${RELEASE_SKIP_PUBLISH:-false}" \
  RELEASE_SKIP_GITHUB_RELEASE="${RELEASE_SKIP_GITHUB_RELEASE:-false}" \
  RELEASE_HEAD="${RELEASE_HEAD:-false}" \
  RELEASE_FROM_ARTIFACTS="${RELEASE_FROM_ARTIFACTS:-}" \
  MOCK_BRANCH="${MOCK_BRANCH:-main}" \
  MOCK_HEAD_SHA="${MOCK_HEAD_SHA:-}" \
  MOCK_RELEASE_BRANCH_SHA="${MOCK_RELEASE_BRANCH_SHA:-}" \
  GH_TOKEN="${GH_TOKEN:-}" \
  HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS="${HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS:-10}" \
  HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS="${HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS:-1}" \
  HOMEBOY_ACTION_REQUIRE_CONTAINMENT_PROOF=false \
  HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS="${HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS:-1}" \
  HOMEBOY_ACTION_PHASE_BUDGET_SECONDS="${HOMEBOY_ACTION_PHASE_BUDGET_SECONDS:-1}" \
  HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TEST_DIR}/phases.jsonl" \
  RUNNER_TEMP="${TEST_DIR}" \
  bash "${RUN_RELEASE_WITH_LIVENESS}"
}

assert_no_git_pull() {
  local label="$1"
  if grep -q '^pull ' "${MOCK_GIT_LOG}"; then
    printf 'FAIL: %s\nwrapper invoked git pull (now owned by homeboy core):\n' "${label}"
    cat "${MOCK_GIT_LOG}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
GH_TOKEN="secret123"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains 'mock-component' "${ARGS}" "release passes component id"
assert_contains '--path' "${ARGS}" "release passes component path"
assert_contains '--skip-checks' "${ARGS}" "release skips duplicate checks"
assert_not_contains '--skip-publish' "${ARGS}" "release runs package/publish steps by default"
assert_not_contains '--no-github-release' "${ARGS}" "release creates GitHub Releases by default"
assert_contains '--git-identity bot' "${ARGS}" "release delegates bot identity to homeboy"
assert_not_contains '--dry-run' "${ARGS}" "normal release uses one non-dry-run homeboy invocation"
assert_contains '--apply' "${ARGS}" "real release pairs --skip-checks with the required --apply opt-in"
assert_output_line 'x-access-token' "${HOMEBOY_AUTH_FILE}" "release exposes GitHub token username through askpass"
assert_output_line 'secret123' "${HOMEBOY_AUTH_FILE}" "release exposes GitHub token password through askpass"
assert_output_line '0' "${HOMEBOY_AUTH_FILE}" "release disables interactive git prompts"
assert_output_line 'released=true' "${OUTPUT_FILE}" "released output is true"
assert_output_line 'release-version=2.1.0' "${OUTPUT_FILE}" "release version output is translated"
assert_output_line 'release-tag=v2.1.0' "${OUTPUT_FILE}" "release tag output is translated"
assert_output_line 'release-bump-type=minor' "${OUTPUT_FILE}" "release bump type output is translated"
assert_output_line 'bump-type=minor' "${OUTPUT_FILE}" "legacy step bump type output is preserved"
assert_no_git_pull "wrapper does not run its own git pull (core owns tip-sync)"

setup_fixture
mkdir -p "${WORKSPACE}/packages/gutenberg"
printf '%s\n' '{"id":"wp-native-gutenberg"}' > "${WORKSPACE}/packages/gutenberg/homeboy.json"
HOMEBOY_MOCK_SCENARIO="released"
COMPONENT_NAME="packages/gutenberg"
PORTABLE_ID="wp-native-gutenberg"
COMPONENT_DIR="packages/gutenberg"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains 'wp-native-gutenberg --path '"${WORKSPACE}"'/packages/gutenberg' "${ARGS}" "subdirectory release separates component identity from workspace path"
assert_not_contains 'packages/gutenberg --path' "${ARGS}" "subdirectory release never uses the directory as component identity"
unset COMPONENT_NAME PORTABLE_ID COMPONENT_DIR

# THE STRANDED-DRAFT REGRESSION (homeboy#10685).
# `homeboy release` can report success while its github.release step leaves an
# unpublished draft over the pushed tag — that is exactly how homeboy-action
# v2.8.26/v2.8.27/v2.9.0/v2.9.1 stranded on 2026-07-28 with the floating v2 tag
# frozen. A green `homeboy release` is therefore NOT proof of a shipped
# release, so the wrapper must independently assert the published state and go
# red when it finds a draft.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
GH_TOKEN="secret123"
MOCK_RELEASE_DRAFT_STATE="true"
DRAFT_OUTPUT="$(run_wrapper 2>&1)" && {
  printf 'FAIL: wrapper reported success over an unpublished draft release\n%s\n' "${DRAFT_OUTPUT}"
  exit 1
}
printf 'PASS: successful homeboy release over an unpublished draft exits non-zero\n'
assert_contains 'is still an unpublished DRAFT' "${DRAFT_OUTPUT}" "stranded draft is named in the CI annotation"
assert_contains 'gh release edit v2.1.0 --draft=false' "${DRAFT_OUTPUT}" "operator repair command is printed next to the stranded draft"
assert_output_line 'released=false' "${OUTPUT_FILE}" "stranded draft is not reported as released"
assert_output_line 'skipped-reason=release-not-published' "${OUTPUT_FILE}" "stranded draft reports the release-not-published reason"
unset MOCK_RELEASE_DRAFT_STATE

setup_fixture
HOMEBOY_MOCK_SCENARIO="skipped"
GH_TOKEN="secret123"
run_wrapper
assert_output_line 'released=false' "${OUTPUT_FILE}" "skipped release output is false"
assert_output_line 'skipped-reason=no-releasable-commits' "${OUTPUT_FILE}" "skipped reason comes from homeboy"
assert_output_line 'release-bump-type=patch' "${OUTPUT_FILE}" "skipped release preserves bump type when present"

# GitHub-release opt-out against a NEWER homeboy binary that advertises (and
# requires, via the #6137 guard) the confirmation flag: the wrapper must pass
# both --no-github-release and --i-know-ci-creates-the-github-release.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
HOMEBOY_SUPPORTS_CONFIRM_FLAG="true"
RELEASE_SKIP_PUBLISH="true"
RELEASE_SKIP_GITHUB_RELEASE="true"
GH_TOKEN="secret123"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains '--skip-publish' "${ARGS}" "release can opt out of package/publish steps"
assert_contains '--no-github-release' "${ARGS}" "release can opt out of GitHub Release creation"
assert_contains '--i-know-ci-creates-the-github-release' "${ARGS}" "CI opt-out confirms the flag when the binary supports it"
unset RELEASE_SKIP_PUBLISH RELEASE_SKIP_GITHUB_RELEASE HOMEBOY_SUPPORTS_CONFIRM_FLAG

# GitHub-release opt-out against an OLDER homeboy binary that PREDATES the
# confirmation flag (and the guard): the wrapper must pass --no-github-release
# alone and must NOT pass the flag, which an older binary would reject with
# "unexpected argument". This is the regression #263 introduced.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
HOMEBOY_SUPPORTS_CONFIRM_FLAG="false"
RELEASE_SKIP_PUBLISH="true"
RELEASE_SKIP_GITHUB_RELEASE="true"
GH_TOKEN="secret123"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains '--no-github-release' "${ARGS}" "older binary still opts out of GitHub Release creation"
assert_not_contains '--i-know-ci-creates-the-github-release' "${ARGS}" "older binary that lacks the flag is not handed it"
unset RELEASE_SKIP_PUBLISH RELEASE_SKIP_GITHUB_RELEASE HOMEBOY_SUPPORTS_CONFIRM_FLAG

setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
RELEASE_HEAD="true"
RELEASE_FROM_ARTIFACTS="artifacts"
MOCK_BRANCH="HEAD"
GH_TOKEN="secret123"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains '--head' "${ARGS}" "head release passes --head"
assert_contains '--from-artifacts' "${ARGS}" "head release passes --from-artifacts"
assert_contains 'artifacts' "${ARGS}" "head release passes artifact directory"
assert_output_line 'released=true' "${OUTPUT_FILE}" "head release can run from detached HEAD"
unset RELEASE_HEAD RELEASE_FROM_ARTIFACTS MOCK_BRANCH

setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
RELEASE_DRY_RUN="true"
GH_TOKEN="secret123"
run_wrapper
ARGS="$(cat "${HOMEBOY_ARGS_FILE}")"
assert_contains '--dry-run' "${ARGS}" "dry-run mode passes through to homeboy"
assert_not_contains '--apply' "${ARGS}" "dry-run never passes --apply (mutually exclusive with --dry-run)"
assert_output_line 'released=false' "${OUTPUT_FILE}" "dry-run output is not released"
assert_output_line 'release-version=2.1.0' "${OUTPUT_FILE}" "dry-run preserves planned version"
assert_output_line 'release-tag=v2.1.0' "${OUTPUT_FILE}" "dry-run preserves planned tag"
assert_output_line 'release-bump-type=minor' "${OUTPUT_FILE}" "dry-run preserves planned bump"
assert_output_line 'skipped-reason=dry-run' "${OUTPUT_FILE}" "dry-run reason is action glue"
# RELEASE_DRY_RUN leaked into every test below this point — each `run_wrapper`
# defaults it with `${RELEASE_DRY_RUN:-false}`, so once set it stays set for the
# rest of the file. The later assertions happened not to depend on it, so
# nothing caught it. Unset it so each block starts from the documented default.
unset RELEASE_DRY_RUN

# Extra-Chill/homeboy#10441: a release that fails a step returns the v3
# envelope, which has no `.error`. Reading only `.error.message` printed
# "Release failed: Unknown error" while the payload held the classified reason
# AND a ready-to-paste repair command — the pipeline computed the answer and
# printed prose. The wrapper must surface both.
setup_fixture
HOMEBOY_MOCK_SCENARIO="step-failed"
GH_TOKEN="secret123"
set +e
FAILURE_OUTPUT="$(run_wrapper 2>&1)"
FAILURE_EXIT=$?
set -e
if [ "${FAILURE_EXIT}" -eq 0 ]; then
  printf 'FAIL: failed release must exit non-zero\n%s\n' "${FAILURE_OUTPUT}"
  exit 1
fi
printf 'PASS: failed release exits non-zero\n'
assert_not_contains 'Release failed: Unknown error' "${FAILURE_OUTPUT}" "classified step failure is never reported as Unknown error"
assert_contains 'gh-upload-failed' "${FAILURE_OUTPUT}" "classified failure reason reaches the CI annotation"
assert_contains "gh release upload 'v2.1.0' --clobber" "${FAILURE_OUTPUT}" "repair command is printed next to the failure"
assert_output_line 'released=false' "${OUTPUT_FILE}" "failed release reports released=false"
assert_output_line 'skipped-reason=release-failed' "${OUTPUT_FILE}" "failed release reports the release-failed reason"

# The legacy `.error.message` envelope (validation errors, load failures) must
# keep working — the new lookup adds fallbacks, it does not replace the field.
setup_fixture
HOMEBOY_MOCK_SCENARIO="failed"
GH_TOKEN="secret123"
set +e
LEGACY_OUTPUT="$(run_wrapper 2>&1)"
LEGACY_EXIT=$?
set -e
if [ "${LEGACY_EXIT}" -eq 0 ]; then
  printf 'FAIL: legacy failure must exit non-zero\n%s\n' "${LEGACY_OUTPUT}"
  exit 1
fi
printf 'PASS: legacy failure exits non-zero\n'
assert_contains 'Release failed: mock failure' "${LEGACY_OUTPUT}" "legacy .error.message envelope still reports its message"


# ── Detached HEAD (Extra-Chill/homeboy#10703) ──
#
# `actions/checkout` with `ref: <sha>` detaches HEAD, so
# `git rev-parse --abbrev-ref HEAD` returns the LITERAL string "HEAD". The
# branch guard compared that to RELEASE_BRANCH, decided "not on main" for a
# commit that WAS main's tip, and exited 0 without invoking homeboy. The caller
# read the missing release-version as "nothing to release" and skipped every
# job while reporting success — for 131 commits.
#
# These assert the EFFECT: what the wrapper decides and whether homeboy ran at
# all, not which strings the script contains.

# HEAD detached AT the release branch tip: this IS main. Release must proceed.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
MOCK_BRANCH="HEAD"
MOCK_HEAD_SHA="86c1afc9b8db41ef029345a1b47201d95563942e"
MOCK_RELEASE_BRANCH_SHA="86c1afc9b8db41ef029345a1b47201d95563942e"
GH_TOKEN="secret123"
DETACHED_OUTPUT="$(run_wrapper 2>&1)"
assert_contains 'releasing as main' "${DETACHED_OUTPUT}" "detached HEAD at the tip is recognised as the release branch"
assert_not_contains 'skipping release' "${DETACHED_OUTPUT}" "detached HEAD at the tip must not be skipped as wrong-branch"
# The load-bearing assertion: homeboy actually RAN. The bug's signature is that
# it never did, so no args file existed and no version was ever computed.
if [ ! -f "${HOMEBOY_ARGS_FILE}" ]; then
  printf 'FAIL: homeboy release was never invoked from a detached HEAD at the release branch tip\n%s\n' "${DETACHED_OUTPUT}"
  exit 1
fi
printf 'PASS: homeboy release is invoked from a detached HEAD at the release branch tip\n'
assert_output_line 'release-version=2.1.0' "${OUTPUT_FILE}" "detached HEAD at the tip still computes a version"
assert_not_contains '--head' "$(cat "${HOMEBOY_ARGS_FILE}")" "resolving the branch must NOT smuggle in --head, which would skip version computation"
unset MOCK_BRANCH MOCK_HEAD_SHA MOCK_RELEASE_BRANCH_SHA

# HEAD detached at some OTHER commit: genuinely not the release branch. This is
# a measured negative, so the quiet skip is correct and must be preserved.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
MOCK_BRANCH="HEAD"
MOCK_HEAD_SHA="1111111111111111111111111111111111111111"
MOCK_RELEASE_BRANCH_SHA="2222222222222222222222222222222222222222"
GH_TOKEN="secret123"
set +e
OTHER_OUTPUT="$(run_wrapper 2>&1)"
OTHER_EXIT=$?
set -e
if [ "${OTHER_EXIT}" -ne 0 ]; then
  printf 'FAIL: a determinable non-release commit must skip quietly, not fail\n%s\n' "${OTHER_OUTPUT}"
  exit 1
fi
printf 'PASS: a determinable non-release commit skips quietly\n'
assert_output_line 'skipped-reason=wrong-branch' "${OUTPUT_FILE}" "a commit that is not the release branch tip is still wrong-branch"
if [ -f "${HOMEBOY_ARGS_FILE}" ]; then
  printf 'FAIL: homeboy must not run for a commit that is not the release branch\n'
  exit 1
fi
printf 'PASS: homeboy does not run for a commit that is not the release branch\n'
unset MOCK_BRANCH MOCK_HEAD_SHA MOCK_RELEASE_BRANCH_SHA

# HEAD detached and NO ref for the release branch exists: the branch cannot be
# determined at all. Extra-Chill/homeboy#10685 — absence of evidence must never
# be evidence of success. This must FAIL, with a reason that cannot be mistaken
# for a measurement, rather than exit 0 as "wrong-branch".
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
MOCK_BRANCH="HEAD"
MOCK_HEAD_SHA="86c1afc9b8db41ef029345a1b47201d95563942e"
MOCK_RELEASE_BRANCH_SHA=""
GH_TOKEN="secret123"
set +e
UNKNOWN_OUTPUT="$(run_wrapper 2>&1)"
UNKNOWN_EXIT=$?
set -e
if [ "${UNKNOWN_EXIT}" -eq 0 ]; then
  printf 'FAIL: an undeterminable branch must not exit 0 — that is how "unknown" becomes "nothing to release"\n%s\n' "${UNKNOWN_OUTPUT}"
  exit 1
fi
printf 'PASS: an undeterminable branch exits non-zero\n'
assert_contains '::error::' "${UNKNOWN_OUTPUT}" "an undeterminable branch is loud"
assert_output_line 'skipped-reason=branch-undeterminable' "${OUTPUT_FILE}" "an undeterminable branch reports a reason distinct from the measured wrong-branch"
assert_not_contains 'skipped-reason=wrong-branch' "$(cat "${OUTPUT_FILE}")" "unknown must never be reported as the measured wrong-branch negative"
unset MOCK_BRANCH MOCK_HEAD_SHA MOCK_RELEASE_BRANCH_SHA

# release-head still bypasses the branch guard entirely: it finishes an
# already-tagged HEAD, where the branch is irrelevant by construction.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
RELEASE_HEAD="true"
MOCK_BRANCH="HEAD"
MOCK_HEAD_SHA=""
MOCK_RELEASE_BRANCH_SHA=""
GH_TOKEN="secret123"
run_wrapper >/dev/null 2>&1
assert_contains '--head' "$(cat "${HOMEBOY_ARGS_FILE}")" "release-head still finishes an already-tagged HEAD without a resolvable branch"
assert_output_line 'released=true' "${OUTPUT_FILE}" "release-head is unaffected by branch resolution"
unset RELEASE_HEAD MOCK_BRANCH MOCK_HEAD_SHA MOCK_RELEASE_BRANCH_SHA

# Release planning and execution must use the shared liveness boundary rather
# than calling run-release.sh directly. These mocks never publish or mutate git.
setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
RELEASE_DRY_RUN="true"
HOMEBOY_MOCK_DELAY=2
GH_TOKEN="secret123"
PLANNING_OUTPUT="$(run_liveness_wrapper 2>&1)"
if ! jq -e 'select(.phase == "release_planning" and .event == "completed" and .status == "completed" and .owner == "Homeboy release subsystem")' "${TEST_DIR}/phases.jsonl" >/dev/null; then
  printf 'FAIL: dry-run release did not select the release_planning phase\n'
  exit 1
fi
assert_contains 'phase heartbeat::release_planning running' "${PLANNING_OUTPUT}" "release planning emits periodic heartbeat evidence"
assert_contains 'phase budget exceeded::release_planning exceeded its 1s budget; owner: Homeboy release subsystem' "${PLANNING_OUTPUT}" "release planning emits an owning budget warning"
assert_not_contains '::notice title=Homeboy phase heartbeat::release_planning' "${PLANNING_OUTPUT}" "bounded release heartbeats are plain log evidence"
assert_not_contains '::warning title=Homeboy phase budget exceeded::release_planning' "${PLANNING_OUTPUT}" "bounded release budget evidence is not an annotation"
assert_contains 'Retained homeboy release planning log' "${PLANNING_OUTPUT}" "release planning prints bounded retained logs"
assert_output_line 'released=false' "${OUTPUT_FILE}" "liveness wrapper preserves dry-run release output"
assert_output_line 'release-tag=v2.1.0' "${OUTPUT_FILE}" "liveness wrapper preserves planned tag output"
unset RELEASE_DRY_RUN HOMEBOY_MOCK_DELAY

setup_fixture
HOMEBOY_MOCK_SCENARIO="released"
GH_TOKEN="secret123"
EXECUTION_OUTPUT="$(run_liveness_wrapper 2>&1)"
if ! jq -e 'select(.phase == "release_execution" and .event == "completed" and .status == "completed" and .owner == "Homeboy release subsystem")' "${TEST_DIR}/phases.jsonl" >/dev/null; then
  printf 'FAIL: real release did not select the release_execution phase\n'
  exit 1
fi
assert_contains '--apply' "$(cat "${HOMEBOY_ARGS_FILE}")" "release execution retains real-release selection without publishing in the mock"
assert_not_contains '--dry-run' "$(cat "${HOMEBOY_ARGS_FILE}")" "release execution does not plan-only in the mock"
assert_output_line 'released=true' "${OUTPUT_FILE}" "liveness wrapper preserves real-release output"

setup_fixture
HOMEBOY_MOCK_SCENARIO="liveness-timeout"
HOMEBOY_CHILD_PID_FILE="${TEST_DIR}/release-child.pid"
HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=3
GH_TOKEN="secret123"
set +e
TIMEOUT_OUTPUT="$(run_liveness_wrapper 2>&1)"
TIMEOUT_EXIT=$?
set -e
if [ "${TIMEOUT_EXIT}" -ne 124 ]; then
  printf 'FAIL: release timeout exits 124, got %s\n%s\n' "${TIMEOUT_EXIT}" "${TIMEOUT_OUTPUT}"
  exit 1
fi
release_child_pid="$(<"${HOMEBOY_CHILD_PID_FILE}")"
if kill -0 "${release_child_pid}" 2>/dev/null; then
  printf 'FAIL: release timeout leaves descendant process %s alive\n' "${release_child_pid}"
  exit 1
fi
assert_contains 'homeboy release execution exceeded its 3s execution timeout' "${TIMEOUT_OUTPUT}" "release timeout has standard timeout classification"
assert_contains 'Retained homeboy release execution log' "${TIMEOUT_OUTPUT}" "release timeout prints retained logs after cleanup"
printf 'PASS: release liveness timeout cleans descendants without mutation\n'

printf 'All run-release wrapper checks passed.\n'
