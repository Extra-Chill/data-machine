#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

fake_bin="${TMP_DIR}/bin"
mkdir -p "${fake_bin}"

cat > "${fake_bin}/homeboy" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HOMEBOY_IMPORT_CALL_LOG}"
SH
chmod +x "${fake_bin}/homeboy"

export PATH="${fake_bin}:${PATH}"
export GITHUB_ACTION_PATH="${ROOT}"
export GITHUB_WORKSPACE="${TMP_DIR}/workspace"
export HOMEBOY_IMPORT_CALL_LOG="${TMP_DIR}/homeboy.log"

mkdir -p "${GITHUB_WORKSPACE}/homeboy-observations-import/job-a"
mkdir -p "${GITHUB_WORKSPACE}/homeboy-observations-import/job-b/nested"
touch "${GITHUB_WORKSPACE}/homeboy-observations-import/job-a/manifest.json"
touch "${GITHUB_WORKSPACE}/homeboy-observations-import/job-b/nested/manifest.json"

bash "${ROOT}/scripts/core/import-observations.sh" >/tmp/homeboy-import-observations-test.out

if ! grep -q "runs import ${GITHUB_WORKSPACE}/homeboy-observations-import/job-a" "${HOMEBOY_IMPORT_CALL_LOG}"; then
  printf 'FAIL: missing import call for first bundle\n'
  exit 1
fi

if ! grep -q "runs import ${GITHUB_WORKSPACE}/homeboy-observations-import/job-b/nested" "${HOMEBOY_IMPORT_CALL_LOG}"; then
  printf 'FAIL: missing import call for nested bundle\n'
  exit 1
fi

printf 'All observation import checks passed.\n'
