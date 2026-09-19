#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ACTION_FILE="${ROOT_DIR}/action.yml"
INSTALL_BLOCK="$(awk '
  /^    - name: Install extension$/ { in_block = 1 }
  in_block { print }
  in_block && /^    - name: Capture tooling metadata$/ { exit }
' "${ACTION_FILE}")"

if ! grep -q "if: steps.read-config.outputs.portable-extension != ''" <<<"${INSTALL_BLOCK}"; then
  echo "FAIL: Install extension step must run whenever a portable extension is configured" >&2
  exit 1
fi

if grep -q "steps.cache-homeboy.outputs.cache-hit != 'true'" <<<"${INSTALL_BLOCK}"; then
  echo "FAIL: Install extension step must not be gated on the Homeboy cache hit" >&2
  exit 1
fi

echo "Extension cache condition check passed."
