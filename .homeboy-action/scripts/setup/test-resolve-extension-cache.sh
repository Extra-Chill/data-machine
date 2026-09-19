#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/bin" "${tmp}/component"
printf 'fixture lock\n' > "${tmp}/component/Cargo.lock"

cat > "${tmp}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
printf '%s\n' '{"data":{"extension":{"ci_cache":{"namespace":"cargo","key_files":["Cargo.lock"],"paths":[{"root":"home","path":".cargo/registry"},{"root":"homeboy-data","path":"cargo-targets"},{"root":"component","path":"target","env":"CARGO_TARGET_DIR"}]}}}}'
SH
chmod +x "${tmp}/bin/homeboy"

output="${tmp}/output"
PATH="${tmp}/bin:${PATH}" \
  HOME="${tmp}/home" \
  XDG_DATA_HOME="${tmp}/data" \
  COMPONENT_DIR="${tmp}/component" \
  PORTABLE_EXTENSION=rust \
  GITHUB_ENV="${tmp}/env" \
  GITHUB_OUTPUT="${output}" \
  bash "${SCRIPT_DIR}/resolve-extension-cache.sh"

grep -qx 'enabled=true' "${output}"
grep -qx 'namespace=cargo' "${output}"
grep -qx "${tmp}/home/.cargo/registry" "${output}"
grep -qx "${tmp}/data/homeboy/cargo-targets" "${output}"
grep -qx "${tmp}/component/target" "${output}"
grep -q '^fingerprint=[0-9a-f]\{64\}$' "${output}"
grep -qx "CARGO_TARGET_DIR=${tmp}/component/target" "${tmp}/env"

cat > "${tmp}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
printf '%s\n' '{"data":{"extension":{"ci_cache":{"namespace":"cargo","key_files":["Cargo.lock"],"paths":[{"root":"home","path":"../secrets"}]}}}}'
SH
if PATH="${tmp}/bin:${PATH}" HOME="${tmp}/home" COMPONENT_DIR="${tmp}/component" PORTABLE_EXTENSION=rust GITHUB_ENV="${tmp}/unsafe-env" GITHUB_OUTPUT="${tmp}/unsafe-output" bash "${SCRIPT_DIR}/resolve-extension-cache.sh"; then
  echo 'FAIL: unsafe cache path was accepted'
  exit 1
fi

echo 'Extension cache resolver tests passed'
