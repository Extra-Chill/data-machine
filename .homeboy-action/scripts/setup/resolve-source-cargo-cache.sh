#!/usr/bin/env bash
set -euo pipefail

source_input="${SOURCE_PATH:-.}"
source_dir="$(cd "${source_input}" && pwd)"
lock_file="${source_dir}/Cargo.lock"

if [ ! -f "${lock_file}" ]; then
  echo "enabled=false" >> "${GITHUB_OUTPUT}"
  exit 0
fi

lock_digest="$(sha256sum "${lock_file}" | cut -d' ' -f1)"
fingerprint="$(printf 'Cargo.lock:%s\n' "${lock_digest}" | sha256sum | cut -d' ' -f1)"
target_path="${source_input%/}/target"
[ "${source_input}" = "." ] && target_path=target

{
  echo "enabled=true"
  echo "fingerprint=${fingerprint}"
  echo "paths<<HOMEBOY_CACHE_PATHS"
  echo "${HOME}/.cargo/registry"
  echo "${HOME}/.cargo/git"
  echo "${target_path}"
  echo "HOMEBOY_CACHE_PATHS"
} >> "${GITHUB_OUTPUT}"
