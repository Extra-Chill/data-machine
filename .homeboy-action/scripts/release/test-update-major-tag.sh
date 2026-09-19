#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/release/update-major-tag.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin" "${TMP_DIR}/workspace"
printf '2.11.1\n' > "${TMP_DIR}/workspace/VERSION"

cat > "${TMP_DIR}/bin/git" <<'SH'
#!/usr/bin/env bash
case "$*" in
  'rev-parse HEAD') printf '%s\n' "${FIXTURE_HEAD}" ;;
  'ls-remote --heads origin refs/heads/main') printf '%s\trefs/heads/main\n' "${FIXTURE_MAIN}" ;;
  'rev-list -n 1 v2.11.1') printf '%s\n' "${FIXTURE_TAG_COMMIT}" ;;
  *) printf 'Unexpected git invocation: %s\n' "$*" >&2; exit 2 ;;
esac
SH

cat > "${TMP_DIR}/bin/gh" <<'SH'
#!/usr/bin/env bash
if [ "$*" = 'api repos/Extra-Chill/homeboy-action/git/ref/tags/v2.11.1 --jq .object.sha' ]; then
  printf 'release-tag-object\n'
  exit 0
fi
printf '%s\n' "$*" >> "${FIXTURE_GH_LOG}"
SH
chmod +x "${TMP_DIR}/bin/git" "${TMP_DIR}/bin/gh"

run_hook() {
  PATH="${TMP_DIR}/bin:${PATH}" \
    FIXTURE_HEAD="$1" FIXTURE_MAIN="$2" FIXTURE_TAG_COMMIT="$3" \
    FIXTURE_GH_LOG="${TMP_DIR}/gh.log" bash "${SCRIPT}"
}

(
  cd "${TMP_DIR}/workspace"
  run_hook release release release
)
grep -F 'api repos/Extra-Chill/homeboy-action/git/refs/tags/v2 --method PATCH -f sha=release-tag-object -F force=true' "${TMP_DIR}/gh.log" >/dev/null
printf 'PASS: release worktree at origin/main updates v2\n'

: > "${TMP_DIR}/gh.log"
(
  cd "${TMP_DIR}/workspace"
  run_hook stale main stale
)
[ ! -s "${TMP_DIR}/gh.log" ]
printf 'PASS: stale worktree cannot update v2\n'

if (
  cd "${TMP_DIR}/workspace"
  run_hook release release different
) >/dev/null 2>&1; then
  printf 'FAIL: mismatched immutable version tag was accepted\n' >&2
  exit 1
fi
printf 'PASS: mismatched version tag fails closed\n'
