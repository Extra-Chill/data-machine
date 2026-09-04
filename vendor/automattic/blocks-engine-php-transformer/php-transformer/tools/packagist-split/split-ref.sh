#!/usr/bin/env bash
# Split php-transformer/ out of the given source rev and print the resulting
# commit SHA, after verifying the split tree is byte-identical to the source
# subtree. Shared by the CI mirror workflow and backfill.sh.
#
# Usage: php-transformer/tools/packagist-split/split-ref.sh <source-rev>
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "Usage: split-ref.sh <source-rev>" >&2
    exit 64
fi

source_rev="$1"
split_sha=$(git subtree split --prefix=php-transformer "$source_rev")
src_tree=$(git rev-parse "${source_rev}:php-transformer")
split_tree=$(git rev-parse "${split_sha}^{tree}")
if [ "$src_tree" != "$split_tree" ]; then
    echo "Tree mismatch for ${source_rev}: split ${split_tree} != source ${src_tree}" >&2
    exit 1
fi

echo "$split_sha"
