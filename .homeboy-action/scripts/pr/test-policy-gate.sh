#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POLICY_GATE="${SCRIPT_DIR}/policy-gate.sh"

TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

FAKE_BIN="${TMPDIR}/bin"
mkdir -p "${FAKE_BIN}"

cat > "${FAKE_BIN}/homeboy" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [ "$1 $2 $3 $4" != "git pr policy merge" ]; then
  printf 'unexpected homeboy invocation: %s\n' "$*" >&2
  exit 1
fi

if [ "${HOMEBOY_FAKE_SAFE:-true}" = "true" ]; then
  merged="false"
  for arg in "$@"; do
    if [ "${arg}" = "--merge" ]; then
      merged="true"
    fi
  done
  printf '{"success":true,"data":{"safe":true,"allowed":true,"merged":%s,"reason":"safe","report":"## PR policy\\n\\nSafe for merge."}}\n' "${merged}"
  exit 0
fi

printf '{"success":true,"data":{"safe":false,"allowed":false,"merged":false,"reason":"blocked path","report":"## PR policy\\n\\nUnsafe for merge: blocked path"}}\n'
exit 1
EOF
chmod +x "${FAKE_BIN}/homeboy"

cat > "${FAKE_BIN}/gh" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "${PR_STATE:-OPEN}"
EOF
chmod +x "${FAKE_BIN}/gh"

assert_policy() {
  local expected_safe="$1"
  local expected_merged="$2"
  local label="$3"
  shift 3

  local output_file safe merged
  output_file="${TMPDIR}/output-${label// /-}"

  local output status
  set +e
  output="$(PATH="${FAKE_BIN}:${PATH}" GITHUB_OUTPUT="${output_file}" "$@" 2>&1)"
  status=$?
  set -e
  if [ "${status}" -ne 0 ]; then
    printf 'FAIL: %s exited %s\n%s\n' "${label}" "${status}" "${output}"
    exit 1
  fi

  safe="$(awk '/^safe<<HOMEBOY_PR_POLICY$/{getline; print; exit}' "${output_file}")"
  merged="$(awk '/^merged<<HOMEBOY_PR_POLICY$/{getline; print; exit}' "${output_file}")"

  if [ "${safe}" != "${expected_safe}" ] || [ "${merged}" != "${expected_merged}" ]; then
    printf 'FAIL: %s\nexpected safe/merged: %s/%s\nactual safe/merged:   %s/%s\n' \
      "${label}" "${expected_safe}" "${expected_merged}" "${safe}" "${merged}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_reason() {
  local expected="$1"
  local label="$2"
  local output_file="${TMPDIR}/output-${label// /-}"
  local actual
  actual="$(awk '/^reason<<HOMEBOY_PR_POLICY$/{getline; print; exit}' "${output_file}")"
  if [ "${actual}" != "${expected}" ]; then
    printf 'FAIL: %s\nexpected reason: %s\nactual reason:   %s\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

POLICY="${TMPDIR}/policy.yml"
printf 'merge:\n  allowed_paths: ["content/**"]\n' > "${POLICY}"

assert_policy true false "safe no merge" \
  env -C "${TMPDIR}" \
    POLICY_PATH="${POLICY}" \
    PR_NUMBER="7" \
    REPOSITORY="chubes4/world-of-wordpress" \
    PR_AUTHOR="github-actions[bot]" \
    PR_HEAD_REF="world-day/2026-05-10" \
    PR_HEAD_REPO="chubes4/world-of-wordpress" \
    HOMEBOY_FAKE_SAFE="true" \
    bash "${POLICY_GATE}"

assert_policy true true "safe merge" \
  env -C "${TMPDIR}" \
    POLICY_PATH="${POLICY}" \
    POLICY_MERGE="true" \
    POLICY_MERGE_METHOD="squash" \
    PR_NUMBER="7" \
    REPOSITORY="chubes4/world-of-wordpress" \
    PR_AUTHOR="github-actions[bot]" \
    PR_HEAD_REF="world-day/2026-05-10" \
    PR_HEAD_REPO="chubes4/world-of-wordpress" \
    HOMEBOY_FAKE_SAFE="true" \
    bash "${POLICY_GATE}"

assert_policy false false "blocked" \
  env -C "${TMPDIR}" \
    POLICY_PATH="${POLICY}" \
    PR_NUMBER="7" \
    REPOSITORY="chubes4/world-of-wordpress" \
    PR_AUTHOR="github-actions[bot]" \
    PR_HEAD_REF="world-day/2026-05-10" \
    PR_HEAD_REPO="chubes4/world-of-wordpress" \
    HOMEBOY_FAKE_SAFE="false" \
    bash "${POLICY_GATE}"

assert_policy false false "closed PR" \
  env -C "${TMPDIR}" \
    GITHUB_ACTION_PATH="${SCRIPT_DIR}/../.." \
    GITHUB_REPOSITORY="chubes4/world-of-wordpress" \
    POLICY_PATH="${POLICY}" \
    PR_NUMBER="7" \
    REPOSITORY="chubes4/world-of-wordpress" \
    PR_STATE="MERGED" \
    HOMEBOY_FAKE_SAFE="true" \
    bash "${POLICY_GATE}"
assert_reason pr_closed "closed PR"

printf 'All PR policy gate checks passed.\n'
