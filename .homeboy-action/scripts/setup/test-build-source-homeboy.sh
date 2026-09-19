#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="${root}/scripts/setup/build-source-homeboy.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

mkdir -p "${tmp}/bin" "${tmp}/source/target/release" "${tmp}/source/target/debug"
touch "${tmp}/source/Cargo.toml"

cat > "${tmp}/bin/cargo" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "${CARGO_ARGS_LOG}"
case " $* " in
  *" --release "*) profile=release ;;
  *) profile=debug ;;
esac
cat > "${SOURCE_PATH}/target/${profile}/homeboy" <<'BIN'
#!/usr/bin/env bash
printf '%s\n' 'homeboy fixture'
BIN
chmod +x "${SOURCE_PATH}/target/${profile}/homeboy"
EOF
chmod +x "${tmp}/bin/cargo"

cat > "${tmp}/bin/sudo" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" != cp ]; then exit 1; fi
cp "$2" "${INSTALLED_BINARY}"
EOF
chmod +x "${tmp}/bin/sudo"

run_profile() {
  local profile="$1"
  local output="${tmp}/${profile}.output"
  : > "${output}"
  PATH="${tmp}/bin:${PATH}" \
    SOURCE_PATH="${tmp}/source" \
    SOURCE_BUILD_PROFILE="${profile}" \
    CARGO_ARGS_LOG="${tmp}/${profile}.args" \
    INSTALLED_BINARY="${tmp}/bin/homeboy" \
    GITHUB_OUTPUT="${output}" \
    bash "${script}"
  grep -Fq 'built=true' "${output}"
}

run_profile release
grep -Fq 'build --release --locked' "${tmp}/release.args"

run_profile dev
grep -Fq 'build --locked' "${tmp}/dev.args"
if grep -Fq -- '--release' "${tmp}/dev.args"; then
  echo 'dev source build unexpectedly selected the release profile' >&2
  exit 1
fi

if PATH="${tmp}/bin:${PATH}" \
  SOURCE_PATH="${tmp}/source" \
  SOURCE_BUILD_PROFILE=invalid \
  CARGO_ARGS_LOG="${tmp}/invalid.args" \
  INSTALLED_BINARY="${tmp}/bin/homeboy" \
  GITHUB_OUTPUT="${tmp}/invalid.output" \
  bash "${script}"; then
  echo 'invalid source build profile was accepted' >&2
  exit 1
fi

echo 'build-source-homeboy tests passed'
