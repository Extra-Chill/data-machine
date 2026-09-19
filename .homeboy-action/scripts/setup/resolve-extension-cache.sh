#!/usr/bin/env bash
set -euo pipefail

component_input="${COMPONENT_DIR:-.}"
component_dir="$(cd "${component_input}" && pwd)"
extension_id="${PORTABLE_EXTENSION:-}"
homeboy_data_dir="${HOMEBOY_DATA_DIR:-${XDG_DATA_HOME:-${HOME}/.local/share}/homeboy}"

disable_cache() {
  echo "enabled=false" >> "${GITHUB_OUTPUT}"
  exit 0
}

[ -n "${extension_id}" ] || disable_cache
command -v homeboy >/dev/null 2>&1 || disable_cache

extension_json="$(homeboy extension show "${extension_id}" 2>/dev/null || true)"
cache_json="$(jq -c '.data.extension.ci_cache // empty' <<< "${extension_json}" 2>/dev/null || true)"
[ -n "${cache_json}" ] || disable_cache

namespace="$(jq -r '.namespace // empty' <<< "${cache_json}")"
if ! [[ "${namespace}" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "::error::Extension '${extension_id}' declared an invalid CI cache namespace."
  exit 1
fi

resolve_path() {
  local root="$1"
  local relative="$2"
  local base

  if [ -z "${relative}" ] || [[ "${relative}" = /* ]] || [[ "/${relative}/" = *"/../"* ]]; then
    echo "::error::Extension '${extension_id}' declared unsafe CI cache path '${relative}'." >&2
    return 1
  fi

  case "${root}" in
    component) base="${component_dir}" ;;
    home) base="${HOME}" ;;
    homeboy-data) base="${homeboy_data_dir}" ;;
    *)
      echo "::error::Extension '${extension_id}' declared unknown CI cache root '${root}'." >&2
      return 1
      ;;
  esac
  printf '%s/%s\n' "${base%/}" "${relative#./}"
}

cache_path() {
  local root="$1"
  local relative="$2"
  case "${root}" in
    component)
      if [ "${component_input}" = "." ] || [ -z "${component_input}" ]; then
        printf '%s\n' "${relative#./}"
      else
        printf '%s/%s\n' "${component_input%/}" "${relative#./}"
      fi
      ;;
    home) resolve_path "${root}" "${relative}" ;;
    homeboy-data) resolve_path "${root}" "${relative}" ;;
    *) resolve_path "${root}" "${relative}" ;;
  esac
}

paths=""
while IFS=$'\t' read -r root relative env_name; do
  [ -n "${root}" ] || continue
  resolved="$(resolve_path "${root}" "${relative}")"
  paths="${paths}${paths:+$'\n'}$(cache_path "${root}" "${relative}")"
  if [ -n "${env_name}" ]; then
    if ! [[ "${env_name}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
      echo "::error::Extension '${extension_id}' declared invalid CI cache environment name '${env_name}'."
      exit 1
    fi
    printf '%s=%s\n' "${env_name}" "${resolved}" >> "${GITHUB_ENV}"
  fi
done < <(jq -r '.paths[]? | [.root, .path, (.env // "")] | @tsv' <<< "${cache_json}")
[ -n "${paths}" ] || disable_cache

key_material=""
while IFS= read -r relative; do
  [ -n "${relative}" ] || continue
  if [[ "${relative}" = /* ]] || [[ "/${relative}/" = *"/../"* ]]; then
    echo "::error::Extension '${extension_id}' declared unsafe CI cache key file '${relative}'."
    exit 1
  fi
  key_file="${component_dir}/${relative#./}"
  if [ -f "${key_file}" ]; then
    file_digest="$(sha256sum "${key_file}" | cut -d' ' -f1)"
    key_material="${key_material}${relative}:${file_digest}"$'\n'
  fi
done < <(jq -r '.key_files[]?' <<< "${cache_json}")
[ -n "${key_material}" ] || disable_cache

fingerprint="$(printf '%s' "${key_material}" | sha256sum | cut -d' ' -f1)"
{
  echo "enabled=true"
  echo "namespace=${namespace}"
  echo "fingerprint=${fingerprint}"
  echo "paths<<HOMEBOY_CACHE_PATHS"
  printf '%s\n' "${paths}"
  echo "HOMEBOY_CACHE_PATHS"
} >> "${GITHUB_OUTPUT}"

echo "Resolved ${extension_id} CI cache '${namespace}' with $(printf '%s\n' "${paths}" | wc -l | tr -d ' ') paths."
