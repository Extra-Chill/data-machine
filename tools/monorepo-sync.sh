#!/usr/bin/env bash
# Bring the staged monorepo up to date with its source repositories.
#
# Staging-only (Extra-Chill/extrachill#385 playbook). Delete this script once the cutover is done.
#
# For each source repo, the sync point is the newest source commit whose root
# tree equals the monorepo's current `<path>` subtree, i.e. what is already
# imported. Commits after it are replayed into `<path>` with
# `git am --directory`, which keeps authors, dates and messages. Release tags
# on replayed commits become `<repo>-<tag>` tags, the same scheme the import
# used. History is never rewritten, and re-running is a no-op.
#
# Re-running `git filter-repo` for a delta is not deterministic: it rewrites
# abbreviated hashes in commit messages, and those can resolve differently
# than they did at import.
#
# The replay is linear. Source repos squash-merge, so this matches their
# history; merge commits in the range are refused rather than flattened.
#
# Usage: tools/monorepo-sync.sh [repo ...]   (default: every imported repo)
# Run from the monorepo root on monorepo/import. Tags are created locally,
# not pushed.
set -euo pipefail

ORG="${MONOREPO_SYNC_ORG:-Extra-Chill}"
root="$(git rev-parse --show-toplevel)"
cd "${root}"

[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "working tree is dirty" >&2; exit 1; }

# The import commits record the source repo -> path mapping.
mapping="$(git log --format=%s | sed -nE 's/^chore\(monorepo\): import ([a-z0-9-]+) into ([a-z0-9/-]+)$/\1 \2/p' | sort -u)"
[ -n "${mapping}" ] || { echo "no import commits found" >&2; exit 1; }

selected=("$@")
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

synced=0
while read -r repo path; do
  if [ "${#selected[@]}" -gt 0 ] && [[ ! " ${selected[*]} " =~ " ${repo} " ]]; then
    continue
  fi
  src="${work}/${repo}"
  git clone --quiet "https://github.com/${ORG}/${repo}.git" "${src}"
  imported_tree="$(git rev-parse "HEAD:${path}")"
  base="$(git -C "${src}" log --format='%H %T' | awk -v t="${imported_tree}" '$2 == t && !found {print $1; found = 1}')"
  if [ -z "${base}" ]; then
    echo "${repo}: no source commit matches the imported ${path} tree (local edits under ${path}?)" >&2
    exit 1
  fi
  count="$(git -C "${src}" rev-list --count "${base}..HEAD")"
  if [ "${count}" = 0 ]; then
    echo "${repo}: up to date"
    rm -rf "${src}"
    continue
  fi
  if [ -n "$(git -C "${src}" rev-list --merges "${base}..HEAD")" ]; then
    echo "${repo}: merge commits after ${base:0:9}; replay would flatten them" >&2
    exit 1
  fi

  before="$(git rev-parse HEAD)"
  git -C "${src}" format-patch --quiet --binary --full-index -o "${work}/${repo}-patches" "${base}..HEAD"
  git am --quiet --keep-cr --committer-date-is-author-date --directory="${path}" "${work}/${repo}-patches"/*.patch

  # Map each replayed source commit to its new commit (same order) and carry tags.
  paste -d' ' <(git -C "${src}" rev-list --reverse "${base}..HEAD") <(git rev-list --reverse "${before}..HEAD") |
    while read -r old new; do
      git -C "${src}" tag --points-at "${old}" | while read -r tag; do
        git tag -f "${repo}-${tag}" "${new}" >/dev/null
        echo "   tag ${repo}-${tag}"
      done
    done

  # Replay leaves the subtree byte-identical to the source tip.
  [ "$(git rev-parse "HEAD:${path}")" = "$(git -C "${src}" rev-parse 'HEAD^{tree}')" ] || {
    echo "${repo}: replayed tree differs from source HEAD" >&2; exit 1; }
  echo "${repo}: replayed ${count} commit(s) into ${path}"
  synced=$((synced + 1))
  rm -rf "${src}" "${work}/${repo}-patches"
done <<< "${mapping}"

echo "Synced ${synced} repo(s)."
