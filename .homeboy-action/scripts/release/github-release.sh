#!/usr/bin/env bash

set -euo pipefail

homeboy_should_verify_github_release() {
  case "${HOMEBOY_VERIFY_GITHUB_RELEASE:-true}" in
    true|1|yes|on)
      return 0
      ;;
    false|0|no|off)
      return 1
      ;;
    *)
      echo "::error::Invalid HOMEBOY_VERIFY_GITHUB_RELEASE value '${HOMEBOY_VERIFY_GITHUB_RELEASE}'. Expected true or false."
      return 2
      ;;
  esac
}

# Assert that a release actually SHIPPED — not merely that a release object
# exists. `gh release view` exits 0 for an unpublished draft, so the old
# existence check reported "Verified" for releases no consumer could ever
# resolve. On 2026-07-28 four homeboy-action releases (v2.8.26, v2.8.27,
# v2.9.0, v2.9.1) sat as drafts while the floating `v2` tag stayed frozen at
# v2.8.25; `gh release view v2.9.1` exits 0 against every one of them.
#
# The publish state is the effect that matters: a draft never moves `v2`,
# never triggers the post:release hook, and is invisible to `ref: v2`
# consumers. So read `isDraft` and require it to be false. An unreadable or
# unrecognized state fails closed — absence of evidence is not evidence of a
# published release (homeboy#10685).
homeboy_verify_github_release_published() {
  local release_tag="$1"
  local repository="$2"

  if homeboy_should_verify_github_release; then
    :
  else
    local decision=$?
    if [ "${decision}" -eq 2 ]; then
      return 1
    fi

    echo "::notice::Skipping GitHub Release verification for ${repository}@${release_tag}"
    return 0
  fi

  if [ -z "${release_tag}" ]; then
    echo "::error::Cannot verify GitHub Release: release tag is empty"
    return 1
  fi

  if [ -z "${repository}" ]; then
    echo "::error::Cannot verify GitHub Release for ${release_tag}: GITHUB_REPOSITORY is empty"
    return 1
  fi

  if ! command -v gh >/dev/null 2>&1; then
    echo "::error::Cannot verify GitHub Release ${repository}@${release_tag}: gh CLI is not available"
    return 1
  fi

  local draft_state
  if ! draft_state="$(gh release view "${release_tag}" --repo "${repository}" --json isDraft --jq '.isDraft' 2>/dev/null)"; then
    echo "::error::GitHub Release not found after successful release: repo=${repository} tag=${release_tag}"
    echo "::error::Expected 'gh release view ${release_tag} --repo ${repository}' to succeed."
    return 1
  fi

  draft_state="$(printf '%s' "${draft_state}" | tr -d '[:space:]')"

  case "${draft_state}" in
    false)
      echo "::notice::Verified GitHub Release ${repository}@${release_tag} is published"
      return 0
      ;;
    true)
      echo "::error::GitHub Release ${repository}@${release_tag} exists but is still an unpublished DRAFT."
      echo "::error::A draft release does not move floating tags, does not run post:release hooks, and is invisible to consumers pinning a released ref."
      echo "::error::Publish it once its assets verify: gh release edit ${release_tag} --draft=false -R ${repository}"
      return 1
      ;;
    *)
      echo "::error::Could not determine the publish state of GitHub Release ${repository}@${release_tag} (read isDraft='${draft_state}')."
      echo "::error::Refusing to report an unverified release as published."
      return 1
      ;;
  esac
}
