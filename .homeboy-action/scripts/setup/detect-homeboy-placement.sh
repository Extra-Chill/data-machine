#!/usr/bin/env bash

set -euo pipefail

if homeboy --help 2>&1 | grep -E '^[[:space:]]+--placement([[:space:]]|<)' >/dev/null; then
  placement_mode=global
elif homeboy review audit --help 2>&1 | grep -E '^[[:space:]]+--placement([[:space:]]|<)' >/dev/null; then
  placement_mode=scoped
else
  placement_mode=legacy
fi

echo "HOMEBOY_ACTION_PLACEMENT_MODE=${placement_mode}" >> "${GITHUB_ENV}"
