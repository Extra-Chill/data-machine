#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/source"
printf 'fixture lock\n' > "${tmp}/source/Cargo.lock"

output="${tmp}/output"
HOME="${tmp}/home" SOURCE_PATH=. GITHUB_OUTPUT="${output}" bash "${SCRIPT_DIR}/resolve-source-cargo-cache.sh"
grep -qx 'enabled=false' "${output}"

output="${tmp}/source-output"
HOME="${tmp}/home" SOURCE_PATH="${tmp}/source" GITHUB_OUTPUT="${output}" bash "${SCRIPT_DIR}/resolve-source-cargo-cache.sh"
grep -qx 'enabled=true' "${output}"
grep -qx "${tmp}/home/.cargo/registry" "${output}"
grep -qx "${tmp}/source/target" "${output}"
grep -q '^fingerprint=[0-9a-f]\{64\}$' "${output}"

echo 'Source Cargo cache resolver tests passed'
