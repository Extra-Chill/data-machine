#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PREPARE="${ROOT}/scripts/core/prepare-test-shard-workspace.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

for phase in candidate baseline; do
  workspace="${tmp}/${phase} workspace"
  mkdir -p "${workspace}"
  git -C "${workspace}" init -q
  git -C "${workspace}" config user.name fixture
  git -C "${workspace}" config user.email fixture@example.invalid
  printf 'consumer\n' > "${workspace}/consumer.txt"
  git -C "${workspace}" add consumer.txt
  git -C "${workspace}" commit -qm fixture
  git -C "${workspace}" init -q .homeboy-action
  printf 'consumer-change.txt\n' > "$(git -C "${workspace}" rev-parse --path-format=absolute --git-path info/exclude)"

  GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}"
  mkdir -p "${workspace}/.homeboy-action"
  mkdir -p "${workspace}/homeboy-test-archive"
  : > "${workspace}/homeboy-test-archive/homeboy-test-archive.tar.zst"
  : > "${workspace}/homeboy-test-inventory.json"
  : > "${workspace}/homeboy-test-shard-plan.json"
  : > "${workspace}/homeboy-test-shard.json"

  status="$(git -C "${workspace}" status --porcelain --untracked-files=all)"
  [ -z "${status}" ] || { printf 'FAIL: %s replay sees action-owned transport as consumer changes: %s\n' "${phase}" "${status}"; exit 1; }

  printf 'dirty\n' >> "${workspace}/consumer.txt"
  : > "${workspace}/consumer-change.txt"
  mkdir -p "${workspace}/components/nested"
  : > "${workspace}/components/nested/homeboy-test-shard.json"
  status="$(git -C "${workspace}" status --porcelain --untracked-files=all)"
  printf '%s\n' "${status}" | grep -Fx ' M consumer.txt' >/dev/null || { printf 'FAIL: %s replay hid a real tracked consumer change\n' "${phase}"; exit 1; }
  printf '%s\n' "${status}" | grep -Fx '?? consumer-change.txt' >/dev/null || { printf 'FAIL: %s replay hid a real untracked consumer change\n' "${phase}"; exit 1; }
  printf '%s\n' "${status}" | grep -Fx '?? components/nested/homeboy-test-shard.json' >/dev/null || { printf 'FAIL: %s replay hid a nested consumer path\n' "${phase}"; exit 1; }

  GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" cleanup
  cmp <(printf 'consumer-change.txt\n') "$(git -C "${workspace}" rev-parse --path-format=absolute --git-path info/exclude)" || { printf 'FAIL: %s replay did not restore consumer exclusions\n' "${phase}"; exit 1; }
done

workspace="${tmp}/binary-identity"
mkdir -p "${workspace}"
git -C "${workspace}" init -q
git -C "${workspace}" config user.name fixture
git -C "${workspace}" config user.email fixture@example.invalid
printf 'consumer\n' > "${workspace}/consumer.txt"
git -C "${workspace}" add consumer.txt
git -C "${workspace}" commit -qm fixture
git -C "${workspace}" init -q .homeboy-action
printf 'existing-exclusion.txt\n' > "$(git -C "${workspace}" rev-parse --path-format=absolute --git-path info/exclude)"
GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" prepare .homeboy-action
printf 'dirty\n' >> "${workspace}/consumer.txt"
status="$(git -C "${workspace}" status --porcelain --untracked-files=all)"
printf '%s\n' "${status}" | grep -Fx ' M consumer.txt' >/dev/null || { printf 'FAIL: candidate binary identity setup hid a real consumer change\n'; exit 1; }
if printf '%s\n' "${status}" | grep -F .homeboy-action >/dev/null; then
  printf 'FAIL: candidate binary identity setup exposed the action checkout\n'
  exit 1
fi
GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" cleanup
cmp <(printf 'existing-exclusion.txt\n') "$(git -C "${workspace}" rev-parse --path-format=absolute --git-path info/exclude)" || { printf 'FAIL: candidate binary identity setup did not restore consumer exclusions\n'; exit 1; }
printf 'PASS: candidate binary identity setup excludes only the action checkout and restores consumer exclusions\n'

workspace="${tmp}/tracked-collision"
mkdir -p "${workspace}"
git -C "${workspace}" init -q
git -C "${workspace}" config user.name fixture
git -C "${workspace}" config user.email fixture@example.invalid
git -C "${workspace}" init -q .homeboy-action
: > "${workspace}/homeboy-test-inventory.json"
git -C "${workspace}" add homeboy-test-inventory.json
git -C "${workspace}" commit -qm fixture
if GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" >/dev/null 2>&1; then
  printf 'FAIL: action accepted a tracked transport collision\n'
  exit 1
fi

for collision in homeboy-test-archive homeboy-test-inventory.json homeboy-test-shard-plan.json homeboy-test-shard.json; do
  workspace="${tmp}/collision-${collision}"
  mkdir -p "${workspace}"
  git -C "${workspace}" init -q
  git -C "${workspace}" init -q .homeboy-action
  : > "${workspace}/${collision}"
  if GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" >/dev/null 2>&1; then
    printf 'FAIL: action accepted preexisting consumer transport collision: %s\n' "${collision}"
    exit 1
  fi
done

workspace="${tmp}/action-checkout-collision"
mkdir -p "${workspace}/.homeboy-action"
git -C "${workspace}" init -q
printf 'consumer-owned\n' > "${workspace}/.homeboy-action/marker"
git -C "${workspace}" add .homeboy-action
git -C "${workspace}" config user.name fixture
git -C "${workspace}" config user.email fixture@example.invalid
git -C "${workspace}" commit -qm fixture
if ! git -C "${workspace}" ls-files --error-unmatch -- .homeboy-action >/dev/null 2>&1; then
  printf 'FAIL: fixture did not retain a consumer-owned action checkout collision\n'
  exit 1
fi
if GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" >/dev/null 2>&1; then
  printf 'FAIL: action accepted a consumer-owned action checkout collision\n'
  exit 1
fi

workspace="${tmp}/symlink-collision"
mkdir -p "${workspace}"
git -C "${workspace}" init -q
git -C "${workspace}" init -q .homeboy-action
ln -s /tmp "${workspace}/homeboy-test-shard.json"
if GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" >/dev/null 2>&1; then
  printf 'FAIL: action accepted a symlinked transport collision\n'
  exit 1
fi

workspace="${tmp}/action-checkout-symlink"
mkdir -p "${workspace}"
git -C "${workspace}" init -q
ln -s /tmp "${workspace}/.homeboy-action"
if GITHUB_WORKSPACE="${workspace}" bash "${PREPARE}" >/dev/null 2>&1; then
  printf 'FAIL: action accepted a symlinked action checkout collision\n'
  exit 1
fi

primary="${tmp}/linked-primary"
linked="${tmp}/linked workspace"
git init -q "${primary}"
git -C "${primary}" config user.name fixture
git -C "${primary}" config user.email fixture@example.invalid
printf 'consumer\n' > "${primary}/consumer.txt"
git -C "${primary}" add consumer.txt
git -C "${primary}" commit -qm fixture
git -C "${primary}" worktree add -q "${linked}" -b linked-fixture
git -C "${linked}" init -q .homeboy-action
GITHUB_WORKSPACE="${linked}" bash "${PREPARE}"
: > "${linked}/homeboy-test-shard.json"
[ -z "$(git -C "${linked}" status --porcelain)" ] || { printf 'FAIL: linked consumer worktree did not honor ephemeral exclusions\n'; exit 1; }
GITHUB_WORKSPACE="${linked}" bash "${PREPARE}" cleanup

printf 'PASS: candidate and baseline Test shard transport stays out of consumer status without hiding real changes\n'
