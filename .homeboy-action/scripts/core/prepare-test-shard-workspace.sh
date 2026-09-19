#!/usr/bin/env bash

# Temporarily hides action-owned paths from Git status without masking consumer
# changes. The original exclusions are restored exactly by `cleanup`.
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$PWD}"
mode="${1:-prepare}"
shift || true
git -C "${workspace}" rev-parse --is-inside-work-tree >/dev/null || {
  echo "::error::Test shard workspace is not a Git worktree: ${workspace}" >&2
  exit 1
}

git_dir="$(git -C "${workspace}" rev-parse --absolute-git-dir)"
exclude_file="$(git -C "${workspace}" rev-parse --path-format=absolute --git-path info/exclude)"
state_dir="${git_dir}/homeboy-test-shard-workspace-exclude"

case "${mode}" in
  cleanup)
    [ -d "${state_dir}" ] && [ ! -L "${state_dir}" ] || exit 0
    [ -f "${state_dir}/original-exclude" ] && [ ! -L "${state_dir}/original-exclude" ] || {
      echo "::error::Test shard workspace exclusion state is unsafe." >&2
      exit 1
    }
    [ -f "${state_dir}/exclude-existed" ] || [ -f "${state_dir}/exclude-absent" ] || {
      echo "::error::Test shard workspace exclusion state is incomplete." >&2
      exit 1
    }
    [ ! -L "${exclude_file}" ] || {
      echo "::error::Refusing to restore Test shard exclusions through a symlink." >&2
      exit 1
    }
    if [ -f "${state_dir}/exclude-existed" ]; then
      cp "${state_dir}/original-exclude" "${exclude_file}"
    else
      rm -f "${exclude_file}"
    fi
    rm -f "${state_dir}/original-exclude" "${state_dir}/exclude-existed" "${state_dir}/exclude-absent"
    rmdir "${state_dir}"
    exit 0
    ;;
  prepare) ;;
  *)
    echo "::error::Usage: $0 [prepare|cleanup]" >&2
    exit 1
    ;;
esac

paths=("$@")
if [ "${#paths[@]}" -eq 0 ]; then
  paths=(.homeboy-action homeboy-test-archive homeboy-test-inventory.json homeboy-test-shard-plan.json homeboy-test-shard.json)
fi

for path in "${paths[@]}"; do
  case "${path}" in
    .homeboy-action|homeboy-test-archive|homeboy-test-inventory.json|homeboy-test-shard-plan.json|homeboy-test-shard.json) ;;
    *)
      echo "::error::Unsupported action-owned exclusion path: ${path}" >&2
      exit 1
      ;;
  esac
  if git -C "${workspace}" ls-files --error-unmatch -- "${path}" >/dev/null 2>&1 || [ -e "${workspace}/${path}" ] || [ -L "${workspace}/${path}" ]; then
    if [ "${path}" = .homeboy-action ]; then
      continue
    fi
    echo "::error::Refusing to overwrite consumer path reserved for Test shard transport: ${path}" >&2
    exit 1
  fi
done

action_checkout="${workspace}/.homeboy-action"
if [[ " ${paths[*]} " == *" .homeboy-action "* ]] && { [ -L "${action_checkout}" ] || ! action_toplevel="$(git -C "${action_checkout}" rev-parse --show-toplevel 2>/dev/null)" || [ "${action_toplevel}" != "$(cd "${action_checkout}" && pwd -P)" ]; }; then
  echo "::error::Homeboy Action checkout is missing or unsafe." >&2
  exit 1
fi

mkdir -p "$(dirname "${exclude_file}")"
[ ! -e "${state_dir}" ] && [ ! -L "${state_dir}" ] || {
  echo "::error::Test shard workspace exclusion state already exists." >&2
  exit 1
}
if [ -L "${exclude_file}" ] || { [ -e "${exclude_file}" ] && [ ! -f "${exclude_file}" ]; }; then
  echo "::error::Refusing to replace Test shard exclusions through an unsafe path." >&2
  exit 1
fi

mkdir "${state_dir}"
if [ -e "${exclude_file}" ]; then
  cp "${exclude_file}" "${state_dir}/original-exclude"
  : > "${state_dir}/exclude-existed"
else
  : > "${state_dir}/original-exclude"
  : > "${state_dir}/exclude-absent"
fi

# Replace, rather than append to, consumer exclusions while action work runs.
# This prevents a preexisting broad exclusion from hiding a real consumer change.
for path in "${paths[@]}"; do
  case "${path}" in
    .homeboy-action|homeboy-test-archive) printf '/%s/\n' "${path}" ;;
    *) printf '/%s\n' "${path}" ;;
  esac
done > "${exclude_file}"
