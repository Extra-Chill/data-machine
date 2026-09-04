#!/usr/bin/env bash
# Backfill the php-transformer subtree-split mirror with every historical
# php-transformer-v* release tag (translated to vX.Y.Z), then push the
# current origin/trunk split to the mirror's trunk.
#
# Usage:
#   php-transformer/tools/packagist-split/backfill.sh [--dry-run] [mirror-url]
#
# Run from anywhere inside a full (non-shallow) clone of
# Automattic/blocks-engine with origin/trunk fetched. Safe to re-run: subtree
# splits are deterministic, so re-runs reproduce identical mirror history.
# This is also the disaster-recovery path if the mirror repository is lost.
set -euo pipefail

DRY_RUN=0
MIRROR_URL="git@github.com:Automattic/blocks-engine-php-transformer.git"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        *) MIRROR_URL="$arg" ;;
    esac
done

cd "$(git rev-parse --show-toplevel)"
split_ref="php-transformer/tools/packagist-split/split-ref.sh"

if [ "$(git rev-parse --is-shallow-repository)" = "true" ]; then
    echo "Refusing to run from a shallow clone: subtree split needs full history." >&2
    exit 1
fi

refspecs=()

while IFS= read -r tag; do
    version="v${tag#php-transformer-v}"
    split_sha=$("$split_ref" "$tag")
    echo "${tag} -> ${version} (${split_sha})"
    refspecs+=("${split_sha}:refs/tags/${version}")
done < <(git tag --list 'php-transformer-v*' | sort -V)

if [ "${#refspecs[@]}" -eq 0 ]; then
    echo "No php-transformer-v* tags found." >&2
    exit 1
fi

trunk_split=$("$split_ref" origin/trunk)
echo "origin/trunk -> trunk (${trunk_split})"
refspecs+=("${trunk_split}:refs/heads/trunk")

# Branch instead of expanding a possibly-empty array: "${empty[@]}" under
# set -u is an "unbound variable" error on macOS's default bash 3.2.
if [ "$DRY_RUN" = "1" ]; then
    echo "DRY RUN: no refs will be updated on ${MIRROR_URL}"
    git push --dry-run "$MIRROR_URL" "${refspecs[@]}"
else
    git push "$MIRROR_URL" "${refspecs[@]}"
fi
