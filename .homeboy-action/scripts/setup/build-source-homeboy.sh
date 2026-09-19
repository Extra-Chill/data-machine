#!/usr/bin/env bash

set -euo pipefail

profile="${SOURCE_BUILD_PROFILE:-release}"
case "${profile}" in
  release)
    cargo_profile_args=(--release)
    target_profile="release"
    ;;
  dev)
    cargo_profile_args=()
    target_profile="debug"
    ;;
  *)
    echo "::error::Unsupported source-build-profile '${profile}'; expected release or dev"
    exit 1
    ;;
esac

echo "Building homeboy from source at ${SOURCE_PATH} with the ${profile} profile..."

# --locked: never rewrite Cargo.lock during a CI build. Without this, a fresh
# upstream release between commit time and build time can update the lockfile
# and leave the working tree dirty — which makes the downstream `homeboy
# release` working-tree check refuse to release. Reproducible builds want the
# committed lockfile honored verbatim anyway.
BUILD_EXIT=0
cargo build "${cargo_profile_args[@]}" --locked --manifest-path "${SOURCE_PATH}/Cargo.toml" 2>&1 || BUILD_EXIT=$?

if [ "${BUILD_EXIT}" -eq 0 ]; then
  BINARY="${SOURCE_PATH}/target/${target_profile}/homeboy"
  if [ -f "${BINARY}" ]; then
    chmod +x "${BINARY}"
    sudo cp "${BINARY}" /usr/local/bin/homeboy
    echo "Built from source: $(homeboy --version)"
    echo "built=true" >> "${GITHUB_OUTPUT}"
  else
    echo "::warning::Source build succeeded but binary not found — falling back to release"
    echo "built=false" >> "${GITHUB_OUTPUT}"
  fi
else
  echo "::warning::Source build failed (exit ${BUILD_EXIT}) — falling back to release binary"
  echo "built=false" >> "${GITHUB_OUTPUT}"
fi
