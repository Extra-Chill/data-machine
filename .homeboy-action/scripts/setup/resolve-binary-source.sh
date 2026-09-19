#!/usr/bin/env bash

set -euo pipefail

if [ -n "${HAS_BINARY_PATH_INPUT:-}" ]; then
  echo "binary-source=prebuilt" >> "${GITHUB_OUTPUT}"
elif [ -n "${HAS_SOURCE_INPUT}" ] && [ "${SOURCE_BUILT:-}" = "true" ]; then
  echo "binary-source=source" >> "${GITHUB_OUTPUT}"
elif [ -n "${HAS_SOURCE_INPUT}" ]; then
  echo "binary-source=fallback" >> "${GITHUB_OUTPUT}"
  echo "::warning::Using fallback release binary — source build failed"
else
  echo "binary-source=release" >> "${GITHUB_OUTPUT}"
fi

echo "Homeboy binary: $(homeboy --version 2>/dev/null || echo 'not found')"
