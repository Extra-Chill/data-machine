#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/bin"

cat > "${tmp}/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = api ]; then
  printf '%s\n' homeboy-candidate-binary-1 unrelated homeboy-candidate-binary-3 homeboy-candidate-binary-2
  exit 0
fi
if [ "$1 $2" = 'run download' ]; then
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --name) name="$2"; shift 2 ;;
      --dir) destination="$2"; shift 2 ;;
      *) shift ;;
    esac
  done
  cat > "${destination}/homeboy" <<'BIN'
#!/usr/bin/env bash
[ "${FAKE_EMPTY_CLI_REVISION:-}" != 1 ] || exit 0
printf '%s\n' 'homeboy 9.9.9+candidate'
BIN
  exit 0
fi
exit 1
SH
chmod +x "${tmp}/bin/gh"

PATH="${tmp}/bin:${PATH}" \
GITHUB_REPOSITORY=example/repo \
GITHUB_RUN_ID=42 \
GITHUB_OUTPUT="${tmp}/output" \
BINARY_DESTINATION="${tmp}/download" \
bash "${ROOT}/scripts/core/download-candidate-binary.sh" >/dev/null

grep -Fx 'artifact-name=homeboy-candidate-binary-3' "${tmp}/output" >/dev/null || { printf 'FAIL: newest binary attempt was not selected\n'; exit 1; }
expected="$(shasum -a 256 "${tmp}/download/homeboy" | cut -d' ' -f1)"
grep -Fx "binary-sha256=${expected}" "${tmp}/output" >/dev/null || { printf 'FAIL: selected binary digest was not published\n'; exit 1; }
grep -Fx 'cli-revision=homeboy 9.9.9+candidate' "${tmp}/output" >/dev/null || { printf 'FAIL: selected binary CLI revision was not published\n'; exit 1; }
printf 'PASS: candidate binary selection is newest-attempt deterministic, digest-bound, and revision-bound\n'

set +e
invalid_output="$(PATH="${tmp}/bin:${PATH}" GITHUB_REPOSITORY=example/repo GITHUB_RUN_ID=42 GITHUB_OUTPUT="${tmp}/invalid-output" BINARY_DESTINATION="${tmp}/invalid-download" FAKE_EMPTY_CLI_REVISION=1 bash "${ROOT}/scripts/core/download-candidate-binary.sh" 2>&1)"
invalid_status=$?
set -e
if [ "${invalid_status}" -eq 0 ] || [ -s "${tmp}/invalid-output" ]; then
  printf 'FAIL: candidate selector published an empty CLI revision\n%s\n' "${invalid_output}"
  exit 1
fi
case "${invalid_output}" in
  *"did not report a single-line CLI revision"*) ;;
  *) printf 'FAIL: invalid candidate CLI identity lacked actionable evidence\n%s\n' "${invalid_output}"; exit 1 ;;
esac
printf 'PASS: candidate selection fails before handoff when CLI identity is empty\n'
