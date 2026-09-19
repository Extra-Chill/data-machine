#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT_DIR}/.github/workflows/ci.yml"

if [ "$(grep -c '^        id: cargo-cache$' "${WORKFLOW}")" -ne 1 ] \
  || [ "$(grep -c '^          path: \${{ steps.cargo-cache.outputs.paths }}$' "${WORKFLOW}")" -ne 1 ] \
  || [ "$(grep -c '^          key: \${{ steps.cargo-cache.outputs.key }}$' "${WORKFLOW}")" -ne 1 ]; then
  printf 'FAIL: binary job does not resolve and consume one Cargo build cache contract\n'
  exit 1
fi

if grep -q 'cargo-cache-key\|Restore candidate cargo cache' "${WORKFLOW}"; then
  printf 'FAIL: candidate and baseline jobs still duplicate the binary build cache restore\n'
  exit 1
fi

if [ "$(grep -c "if: hashFiles('Cargo.lock') != ''" "${WORKFLOW}")" -ne 1 ]; then
  printf 'FAIL: Cargo cache discovery must remain conditional and owned by the candidate checkout\n'
  exit 1
fi

if [ "$(grep -c '^    - name: Restore extension CI cache$' "${ROOT_DIR}/action.yml")" -ne 1 ] \
  || [ "$(grep -c 'quality-\${{ steps.extension-cache.outputs.fingerprint }}$' "${ROOT_DIR}/action.yml")" -ne 1 ]; then
  printf 'FAIL: composite action does not restore the extension-owned quality cache\n'
  exit 1
fi

printf 'PASS: reusable workflow separates candidate build and extension-owned quality caches\n'
