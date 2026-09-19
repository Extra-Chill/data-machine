#!/usr/bin/env bash
#
# CI-driven continuous release pipeline.
#
# Release decisions belong to `homeboy release`. This wrapper only resolves the
# GitHub Actions workspace, applies branch safety, and translates structured
# Homeboy output into composite-action outputs.
#
# Env vars:
#   RELEASE_BRANCH      - branch to release from (default: main)
#   PORTABLE_ID         - component ID resolved from homeboy.json
#   COMPONENT_NAME      - component ID override
#   RELEASE_DRY_RUN     - if "true", preview without making changes
#   RELEASE_HEAD        - if "true", finish release at existing HEAD/tag
#   RELEASE_FROM_ARTIFACTS - artifact directory passed to --from-artifacts
#
# Outputs (GITHUB_OUTPUT):
#   released:        true|false
#   release-version: the version (e.g. 0.63.0)
#   release-tag:     the git tag (e.g. v0.63.0)
#   release-bump-type: patch|minor|major
#   skipped-reason:  why release was skipped (if released=false)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/github-release.sh"

RELEASE_BRANCH="${RELEASE_BRANCH:-main}"
DRY_RUN="${RELEASE_DRY_RUN:-false}"
RELEASE_HEAD="${RELEASE_HEAD:-false}"
RELEASE_FROM_ARTIFACTS="${RELEASE_FROM_ARTIFACTS:-}"

COMPONENT_DIR="${COMPONENT_DIR:-.}"
if [ -n "${COMPONENT_DIR}" ] && [ "${COMPONENT_DIR}" != "." ]; then
  WORKSPACE="${GITHUB_WORKSPACE:-.}/${COMPONENT_DIR}"
else
  WORKSPACE="${GITHUB_WORKSPACE:-.}"
fi

json_field() {
  local file_path="$1"
  local jq_expr="$2"
  jq -r "${jq_expr}" "${file_path}" 2>/dev/null || true
}

write_output() {
  local key="$1"
  local value="$2"

  echo "${key}=${value}" >> "${GITHUB_OUTPUT}"
}

configure_git_push_auth() {
  if [ -z "${GH_TOKEN:-}" ]; then
    return 0
  fi

  GIT_ASKPASS_SCRIPT="$(mktemp)"
  chmod 700 "${GIT_ASKPASS_SCRIPT}"
  cat > "${GIT_ASKPASS_SCRIPT}" <<'SH'
#!/usr/bin/env bash
case "$1" in
  *Username*) printf '%s\n' 'x-access-token' ;;
  *Password*) printf '%s\n' "${GH_TOKEN}" ;;
  *) printf '\n' ;;
esac
SH

  export GIT_ASKPASS="${GIT_ASKPASS_SCRIPT}"
  export GIT_TERMINAL_PROMPT=0
  trap 'rm -f "${GIT_ASKPASS_SCRIPT:-}"' EXIT
}

resolve_component_id() {
  if [ -n "${PORTABLE_ID:-}" ]; then
    echo "${PORTABLE_ID}"
    return 0
  fi

  if [ -n "${COMPONENT_NAME:-}" ]; then
    echo "${COMPONENT_NAME}"
    return 0
  fi

  if [ -f "${WORKSPACE}/homeboy.json" ]; then
    local configured_id
    configured_id="$(jq -r '.id // empty' "${WORKSPACE}/homeboy.json" 2>/dev/null || true)"
    if [ -n "${configured_id}" ]; then
      echo "${configured_id}"
      return 0
    fi
  fi

  basename "${GITHUB_REPOSITORY:-unknown}"
}

write_release_outputs() {
  local output_file="$1"
  local released="$2"
  local skipped_reason="${3:-}"

  local version tag bump_type
  version="$(json_field "${output_file}" '.data.result.new_version // empty')"
  tag="$(json_field "${output_file}" '.data.result.tag // empty')"
  bump_type="$(json_field "${output_file}" '.data.result.bump_type // empty')"

  write_output "released" "${released}"
  [ -n "${version}" ] && write_output "release-version" "${version}"
  [ -n "${tag}" ] && write_output "release-tag" "${tag}"
  if [ -n "${bump_type}" ]; then
    write_output "release-bump-type" "${bump_type}"
    write_output "bump-type" "${bump_type}"
  fi
  [ -n "${skipped_reason}" ] && write_output "skipped-reason" "${skipped_reason}"

  return 0
}

COMP_ID="$(resolve_component_id)"

configure_git_push_auth

# Identify the branch HEAD is on.
#
# `git rev-parse --abbrev-ref HEAD` returns the LITERAL STRING "HEAD" on a
# detached checkout, and `actions/checkout` detaches whenever it is given
# `ref: <sha>` — the ordinary way a workflow pins every job to the commit that
# triggered it. The guard below compared that literal against RELEASE_BRANCH,
# concluded "not on main" for a commit that WAS main's tip, and exited 0 without
# ever invoking `homeboy release`. No release-version was written, and the
# calling workflow read that emptiness as "nothing to release" — green run,
# every job skipped, 131 commits unreleased (Extra-Chill/homeboy#10703).
#
# When HEAD is detached, identify the branch by COMMIT IDENTITY instead. That is
# also the property this guard actually cares about: not the name HEAD is
# reachable under, but whether the commit being released is the release branch.
#
# This is deliberately NOT solved by RELEASE_HEAD. That variable also appends
# `--head`, which tells homeboy to finish an already-versioned, already-tagged
# HEAD and to skip version and changelog computation entirely. It answers "what
# kind of release is this", not "where am I allowed to release from"; using it
# to silence a branch check would skip the very work a dry run exists to do.
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo HEAD)"
BRANCH_UNDETERMINABLE="false"

if [ "${CURRENT_BRANCH}" = "HEAD" ]; then
  DETACHED_SHA="$(git rev-parse HEAD 2>/dev/null || true)"
  RELEASE_BRANCH_SHA=""
  for candidate_ref in "refs/remotes/origin/${RELEASE_BRANCH}" "refs/heads/${RELEASE_BRANCH}"; do
    RELEASE_BRANCH_SHA="$(git rev-parse -q --verify "${candidate_ref}" 2>/dev/null || true)"
    [ -n "${RELEASE_BRANCH_SHA}" ] && break
  done

  if [ -z "${DETACHED_SHA}" ] || [ -z "${RELEASE_BRANCH_SHA}" ]; then
    BRANCH_UNDETERMINABLE="true"
  elif [ "${DETACHED_SHA}" = "${RELEASE_BRANCH_SHA}" ]; then
    echo "::notice::HEAD is detached at the ${RELEASE_BRANCH} tip ${DETACHED_SHA:0:8} - releasing as ${RELEASE_BRANCH}"
    CURRENT_BRANCH="${RELEASE_BRANCH}"
  else
    echo "::notice::HEAD is detached at ${DETACHED_SHA:0:8}, which is not the ${RELEASE_BRANCH} tip ${RELEASE_BRANCH_SHA:0:8}"
  fi
fi

# UNKNOWN is not a measured negative (Extra-Chill/homeboy#10685). Exiting 0 here
# would report "released=false" with a skip reason, which every caller reads as
# "there was nothing to release" — a claim this script cannot support when it
# could not even establish which branch it is on. Fail loudly instead, with a
# reason that cannot be confused for a measurement.
if [ "${RELEASE_HEAD}" != "true" ] && [ "${BRANCH_UNDETERMINABLE}" = "true" ]; then
  echo "::error::Cannot determine whether HEAD is on ${RELEASE_BRANCH}: HEAD is detached and no ref for ${RELEASE_BRANCH} exists in this checkout. Fetch the branch (actions/checkout with fetch-depth: 0), or set release-head when finishing an already-tagged HEAD. Refusing to report 'nothing to release' for a state that was never measured."
  write_output "released" "false"
  write_output "skipped-reason" "branch-undeterminable"
  exit 1
fi

if [ "${RELEASE_HEAD}" != "true" ] && [ "${CURRENT_BRANCH}" != "${RELEASE_BRANCH}" ]; then
  echo "::notice::Not on ${RELEASE_BRANCH} (current: ${CURRENT_BRANCH}) - skipping release"
  write_output "released" "false"
  write_output "skipped-reason" "wrong-branch"
  exit 0
fi

DEFAULT_BRANCH="$(git symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||' || true)"
if [ -z "${DEFAULT_BRANCH}" ]; then
  DEFAULT_BRANCH="main"
fi

if [ "${RELEASE_HEAD}" != "true" ] && [ "${CURRENT_BRANCH}" != "${DEFAULT_BRANCH}" ]; then
  echo "::error::Refusing to release from non-default branch '${CURRENT_BRANCH}' (default: '${DEFAULT_BRANCH}')"
  write_output "released" "false"
  write_output "skipped-reason" "wrong-default-branch"
  exit 1
fi

# Tip-sync handled in core: `homeboy release` runs `validate_remote_sync`
# which fetches and fast-forwards from origin before the working-tree check,
# and `get_uncommitted_changes` runs `git update-index --refresh` so any
# stale stat info (from prior cargo build / extension setup steps) does not
# surface as a false-positive dirty tree. The wrapper used to do its own
# `git pull --ff-only` here as a workaround, which was the original symptom
# of the upstream gap fixed in homeboy core. See homeboy PR #2368.

# Quality gates run in separate jobs before this script, so --skip-checks
# is always safe here. The publish and github.release flags are
# configurable: downstream consumers that wire up extensions like
# homeboy-extensions/wordpress (release.package + release.publish) want
# the full pipeline by default. Components that publish artifacts or
# create GitHub Releases outside the homeboy pipeline (binary cross-
# compile, hand-rolled gh release create, …) opt out via the action
# inputs that set these env vars.
RELEASE_SKIP_PUBLISH="${RELEASE_SKIP_PUBLISH:-false}"
RELEASE_SKIP_GITHUB_RELEASE="${RELEASE_SKIP_GITHUB_RELEASE:-false}"

RELEASE_OUTPUT_FILE="$(mktemp)"
RELEASE_ARGS=(
  "${COMP_ID}"
  --path "${WORKSPACE}"
  --skip-checks
  --git-identity bot
)

if [ "${RELEASE_SKIP_PUBLISH}" = "true" ]; then
  RELEASE_ARGS+=(--skip-publish)
fi

if [ "${RELEASE_SKIP_GITHUB_RELEASE}" = "true" ]; then
  RELEASE_ARGS+=(--no-github-release)
  # Feature-detect --i-know-ci-creates-the-github-release: newer homeboy cores
  # (PR #6137) GUARD --no-github-release behind this confirmation flag, but the
  # action bootstraps a PUBLISHED homeboy release binary that may PREDATE the
  # flag and would reject it ("unexpected argument"). Older binaries that lack
  # the flag also lack the guard, so --no-github-release alone is correct for
  # them; only append the confirmation when the binary actually advertises it.
  if homeboy release --help 2>/dev/null | grep -q -- '--i-know-ci-creates-the-github-release'; then
    RELEASE_ARGS+=(--i-know-ci-creates-the-github-release)
  fi
fi

if [ "${RELEASE_HEAD}" = "true" ]; then
  RELEASE_ARGS+=(--head)
fi

if [ -n "${RELEASE_FROM_ARTIFACTS}" ]; then
  RELEASE_ARGS+=(--from-artifacts "${RELEASE_FROM_ARTIFACTS}")
fi

if [ "${DRY_RUN}" = "true" ]; then
  RELEASE_ARGS+=(--dry-run)
else
  # Homeboy core requires a real release that bypasses quality gates with
  # --skip-checks to also pass --apply, so an unattended CI release cannot
  # silently ship while a bare --skip-checks would otherwise refuse. Quality
  # gates already ran in separate jobs before this script, so the explicit
  # opt-in is satisfied here.
  RELEASE_ARGS+=(--apply)
fi

set +e
homeboy --output "${RELEASE_OUTPUT_FILE}" release "${RELEASE_ARGS[@]}"
RELEASE_EXIT=$?
set -e

if [ ! -s "${RELEASE_OUTPUT_FILE}" ]; then
  echo "::error::homeboy release did not write structured output to ${RELEASE_OUTPUT_FILE}"
  write_output "released" "false"
  write_output "skipped-reason" "release-output-missing"
  rm -f "${RELEASE_OUTPUT_FILE}"
  exit 1
fi

SUCCESS="$(json_field "${RELEASE_OUTPUT_FILE}" '.success // false')"
SKIPPED_REASON="$(json_field "${RELEASE_OUTPUT_FILE}" '.data.result.skipped_reason // empty')"

if [ -n "${SKIPPED_REASON}" ]; then
  echo "::notice::Release skipped: ${SKIPPED_REASON}"
  write_release_outputs "${RELEASE_OUTPUT_FILE}" "false" "${SKIPPED_REASON}"
  rm -f "${RELEASE_OUTPUT_FILE}"
  exit 0
fi

if [ "${SUCCESS}" != "true" ] || [ "${RELEASE_EXIT}" -ne 0 ]; then
  # `.error.message` only exists on the legacy error envelope. A release that
  # runs and then fails a step returns the v3 command-result envelope, which
  # classifies the failure under `.diagnostics` and `.summary` and carries the
  # repair commands in `.next_actions` — so reading `.error.message` alone
  # printed "Release failed: Unknown error" while the payload held
  # `reason: "gh-upload-failed"` and a ready-to-paste `gh release ...` command
  # (Extra-Chill/homeboy#10441). Read the classified fields first.
  ERROR_MSG="$(json_field "${RELEASE_OUTPUT_FILE}" \
    '[.error.message?, .diagnostics.message?, .diagnostics.failure_digest.summary?, .summary?]
     | map(select(type == "string" and . != "")) | first // "Unknown error"')"
  if [ -z "${ERROR_MSG}" ] || [ "${ERROR_MSG}" = "null" ]; then
    ERROR_MSG="Unknown error"
  fi

  echo "::error::Release failed: ${ERROR_MSG}"

  # The repair commands are the whole point of the structured envelope: print
  # them next to the failure instead of leaving them buried in the JSON.
  while IFS= read -r action; do
    [ -n "${action}" ] || continue
    echo "::notice::Release repair: ${action}"
  done < <(json_field "${RELEASE_OUTPUT_FILE}" \
    '.next_actions? // [] | .[] | select(.command? != null and .command != "")
     | "\(.label // "next action") → \(.command)"')

  write_release_outputs "${RELEASE_OUTPUT_FILE}" "false" "release-failed"
  rm -f "${RELEASE_OUTPUT_FILE}"
  exit 1
fi

VERSION="$(json_field "${RELEASE_OUTPUT_FILE}" '.data.result.new_version // empty')"
TAG="$(json_field "${RELEASE_OUTPUT_FILE}" '.data.result.tag // empty')"
BUMP_TYPE="$(json_field "${RELEASE_OUTPUT_FILE}" '.data.result.bump_type // empty')"

if [ "${DRY_RUN}" = "true" ]; then
  echo "::notice::Dry run - would release ${TAG:-v${VERSION}} (${BUMP_TYPE})"
  write_release_outputs "${RELEASE_OUTPUT_FILE}" "false" "dry-run"
  rm -f "${RELEASE_OUTPUT_FILE}"
  exit 0
fi

# `homeboy release` reporting success is not proof that the release shipped.
# The github.release step creates a DRAFT and publishes it in a later call; if
# that publish never lands, the tag exists over an unpublished draft and the
# pipeline still looks green. Assert the published effect before announcing a
# release. Skipped when this component's GitHub Release is created elsewhere
# (--no-github-release), because then there is nothing for us to assert.
if [ "${RELEASE_SKIP_GITHUB_RELEASE}" != "true" ]; then
  if ! homeboy_verify_github_release_published "${TAG:-v${VERSION}}" "${GITHUB_REPOSITORY:-}"; then
    write_release_outputs "${RELEASE_OUTPUT_FILE}" "false" "release-not-published"
    rm -f "${RELEASE_OUTPUT_FILE}"
    exit 1
  fi
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Released ${TAG} (${BUMP_TYPE})"
echo "  Tag pushed - build/publish workflow will trigger"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

write_release_outputs "${RELEASE_OUTPUT_FILE}" "true"
rm -f "${RELEASE_OUTPUT_FILE}"
