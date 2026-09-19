#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="${ROOT_DIR}/scripts/release/github-release.sh"

source "${HELPER}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_success() {
  local label="$1"
  shift

  if "$@" >"${TMP_DIR}/stdout" 2>"${TMP_DIR}/stderr"; then
    printf 'PASS: %s\n' "${label}"
    return 0
  fi

  printf 'FAIL: %s\n' "${label}"
  printf 'stdout:\n%s\n' "$(cat "${TMP_DIR}/stdout")"
  printf 'stderr:\n%s\n' "$(cat "${TMP_DIR}/stderr")"
  exit 1
}

assert_failure_contains() {
  local expected="$1"
  local label="$2"
  shift 2

  if "$@" >"${TMP_DIR}/stdout" 2>"${TMP_DIR}/stderr"; then
    printf 'FAIL: %s\nexpected failure containing: %s\n' "${label}" "${expected}"
    exit 1
  fi

  if ! grep -q -- "${expected}" "${TMP_DIR}/stdout" "${TMP_DIR}/stderr"; then
    printf 'FAIL: %s\nmissing expected output: %s\n' "${label}" "${expected}"
    printf 'stdout:\n%s\n' "$(cat "${TMP_DIR}/stdout")"
    printf 'stderr:\n%s\n' "$(cat "${TMP_DIR}/stderr")"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

# The fake gh mirrors the real contract: `gh release view --json isDraft --jq
# .isDraft` prints `true` for a draft and `false` for a published release, and
# exits non-zero when no release exists. Tests assert the VERDICT the helper
# reaches from that output, not the command string it happens to build — a
# gate that asserts an invocation cannot notice that the invocation proves
# nothing (homeboy#10685).
write_fake_gh() {
  local exit_code="$1"
  local stdout="${2:-}"
  cat >"${TMP_DIR}/gh" <<EOF
#!/usr/bin/env bash
printf '%s\n' "\$*" > "${TMP_DIR}/gh-args"
printf '%s' '${stdout}'
exit ${exit_code}
EOF
  chmod +x "${TMP_DIR}/gh"
}

# A published release is the only state that may pass.
write_fake_gh 0 'false'
PATH="${TMP_DIR}:${PATH}" assert_success \
  "accepts a published GitHub Release" \
  homeboy_verify_github_release_published "v1.2.3" "Extra-Chill/homeboy-action"

if ! grep -q -- '--json isDraft' "${TMP_DIR}/gh-args"; then
  printf 'FAIL: verification did not ask GitHub for the publish state\n'
  printf 'args: %s\n' "$(cat "${TMP_DIR}/gh-args")"
  exit 1
fi
printf 'PASS: verification reads the release publish state\n'

# THE REGRESSION THIS GUARDS: `gh release view` exits 0 for a draft, so an
# existence check reported success for the four stranded homeboy-action
# drafts that left the floating v2 tag frozen for two days.
write_fake_gh 0 'true'
PATH="${TMP_DIR}:${PATH}" assert_failure_contains \
  "is still an unpublished DRAFT" \
  "rejects a release that exists but was never published" \
  homeboy_verify_github_release_published "v2.9.1" "Extra-Chill/homeboy-action"

# Fail closed: an unreadable publish state is not a published release.
write_fake_gh 0 ''
PATH="${TMP_DIR}:${PATH}" assert_failure_contains \
  "Could not determine the publish state" \
  "refuses to pass when the publish state is unreadable" \
  homeboy_verify_github_release_published "v1.2.3" "Extra-Chill/homeboy-action"

write_fake_gh 1 ''
PATH="${TMP_DIR}:${PATH}" assert_failure_contains \
  "GitHub Release not found after successful release: repo=Extra-Chill/homeboy-action tag=v9.9.9" \
  "fails clearly when GitHub Release is missing" \
  homeboy_verify_github_release_published "v9.9.9" "Extra-Chill/homeboy-action"

HOMEBOY_VERIFY_GITHUB_RELEASE=false assert_success \
  "can skip verification for tag-only release consumers" \
  homeboy_verify_github_release_published "v1.2.3" "Extra-Chill/homeboy-action"

HOMEBOY_VERIFY_GITHUB_RELEASE=maybe assert_failure_contains \
  "Invalid HOMEBOY_VERIFY_GITHUB_RELEASE value" \
  "rejects invalid verification configuration" \
  homeboy_verify_github_release_published "v1.2.3" "Extra-Chill/homeboy-action"

assert_failure_contains \
  "release tag is empty" \
  "fails clearly when tag is empty" \
  homeboy_verify_github_release_published "" "Extra-Chill/homeboy-action"

assert_failure_contains \
  "GITHUB_REPOSITORY is empty" \
  "fails clearly when repository is empty" \
  homeboy_verify_github_release_published "v1.2.3" ""

printf 'All GitHub Release verification checks passed.\n'
