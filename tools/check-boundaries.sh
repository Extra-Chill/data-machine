#!/usr/bin/env bash
# Plugin boundaries in the Data Machine monorepo.
#
# One repository, separate plugins. Core (plugins/data-machine) is the generic
# layer and must not know its extensions exist; each extension builds on core
# and must not reach into a sibling extension. Living in one tree makes those
# shortcuts easy, so this check makes them visible.
#
# A boundary reference is a code identifier owned by another plugin: its PHP
# namespace, its constant prefix, its ability namespace, its post type or its
# directory. Prose such as issue links (data-machine-events#870) is not flagged.
#
# tools/boundary-allowlist.txt lists core files that already cross the line
# when the monorepo was created. It only shrinks: a new file fails, and a
# listed file that no longer crosses the line fails until it is removed.
#
# Usage: tools/check-boundaries.sh   (from anywhere inside the repository)
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "${root}"

extensions=(events socials business)
allowlist="tools/boundary-allowlist.txt"

# Identifiers owned by the given extensions, e.g. "events socials".
pattern_for() {
  local names=("$@") lower upper title
  lower="$(IFS='|'; echo "${names[*]}")"
  upper="$(echo "${lower}" | tr '[:lower:]' '[:upper:]')"
  title="$(echo "${lower}" | sed -E 's/(^|\|)([a-z])/\1\u\2/g')"
  printf '%s' "DataMachine(${title})\\\\|DATAMACHINE_(${upper})_|data-machine-(${lower})/|data_?machine_?(${lower})\\b|plugins/data-machine-(${lower})"
}

# Code only: docs, changelogs and READMEs may describe the ecosystem.
code_paths=(':(exclude)**/docs/**' ':(exclude)**/CHANGELOG.md' ':(exclude)**/README.md' ':(exclude)**/readme.txt' ':(exclude)**/vendor/**')

fail=0

# Core must not reference any extension.
core_hits="$(git grep -lE "$(pattern_for "${extensions[@]}")" -- plugins/data-machine "${code_paths[@]}" || true)"
allowed="$(grep -vE '^\s*(#|$)' "${allowlist}" || true)"

while IFS= read -r file; do
  [ -n "${file}" ] || continue
  if ! grep -qxF "${file}" <<< "${allowed}"; then
    echo "::error file=${file}::core references an extension. Core is the generic layer: move the extension-specific code into the extension, or expose a hook or ability that the extension implements."
    git grep -nE "$(pattern_for "${extensions[@]}")" -- "${file}" | sed 's/^/    /'
    fail=1
  fi
done <<< "${core_hits}"

while IFS= read -r file; do
  [ -n "${file}" ] || continue
  if ! grep -qxF "${file}" <<< "${core_hits}"; then
    echo "::error file=${allowlist}::${file} no longer references an extension. Remove it from ${allowlist}."
    fail=1
  fi
done <<< "${allowed}"

# Extensions must not reference each other.
for ext in "${extensions[@]}"; do
  others=()
  for other in "${extensions[@]}"; do
    [ "${other}" = "${ext}" ] || others+=("${other}")
  done
  if hits="$(git grep -nE "$(pattern_for "${others[@]}")" -- "plugins/data-machine-${ext}" "${code_paths[@]}")"; then
    echo "::error::data-machine-${ext} references a sibling extension. Extensions depend on core only:"
    echo "${hits}" | sed 's/^/    /'
    fail=1
  fi
done

if [ "${fail}" -eq 0 ]; then
  echo "Plugin boundaries hold: core references no extension (${allowlist} lists $(grep -cvE '^\s*(#|$)' "${allowlist}" || true) pre-existing file(s)), and no extension references a sibling."
fi
exit "${fail}"
