#!/usr/bin/env bash

set -euo pipefail

release_version="$(tr -d '[:space:]' < VERSION)"
release_tag="v${release_version}"
head_sha="$(git rev-parse HEAD)"
main_sha="$(git ls-remote --heads origin refs/heads/main | awk 'NR == 1 { print $1 }')"

if [ -z "${main_sha}" ]; then
  printf 'Unable to resolve origin/main before updating v2.\n' >&2
  exit 1
fi

if [ "${head_sha}" != "${main_sha}" ]; then
  printf 'Skipping v2 tag update: release HEAD %s is not origin/main %s.\n' "${head_sha}" "${main_sha}"
  exit 0
fi

release_commit="$(git rev-list -n 1 "${release_tag}")"
if [ "${release_commit}" != "${head_sha}" ]; then
  printf 'Refusing v2 tag update: %s resolves to %s, expected release HEAD %s.\n' "${release_tag}" "${release_commit}" "${head_sha}" >&2
  exit 1
fi

release_ref_sha="$(gh api "repos/Extra-Chill/homeboy-action/git/ref/tags/${release_tag}" --jq .object.sha)"
if [ -z "${release_ref_sha}" ]; then
  printf 'Unable to resolve immutable release ref %s.\n' "${release_tag}" >&2
  exit 1
fi

gh api repos/Extra-Chill/homeboy-action/git/refs/tags/v2 \
  --method PATCH \
  -f sha="${release_ref_sha}" \
  -F force=true
