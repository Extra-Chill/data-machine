#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

assert_equals() {
  local expected="$1"
  local actual="$2"
  local label="$3"

  if [ "${expected}" != "${actual}" ]; then
    printf 'FAIL: %s\nexpected: %s\nactual:   %s\n' "${label}" "${expected}" "${actual}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

assert_contains() {
  local needle="$1"
  local file="$2"
  local label="$3"

  if ! grep -qF "${needle}" "${file}"; then
    printf 'FAIL: %s\nmissing: %s\n' "${label}" "${needle}"
    printf 'log:\n'
    cat "${file}"
    exit 1
  fi

  printf 'PASS: %s\n' "${label}"
}

TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

FAKE_BIN="${TMPDIR}/bin"
mkdir -p "${FAKE_BIN}"

cat > "${FAKE_BIN}/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

endpoint=""
for arg in "$@"; do
  case "${arg}" in
    repos/*) endpoint="${arg}" ;;
  esac
done

case "${endpoint}" in
  repos/Extra-Chill/homeboy/releases/tags/*)
    tag="${endpoint##*/}"
    attempts_file="${FAKE_STATE_DIR}/${tag}.attempts"
    count=0
    if [ -f "${attempts_file}" ]; then
      count="$(cat "${attempts_file}")"
    fi
    count=$((count + 1))
    printf '%s' "${count}" > "${attempts_file}"

    assets_var="FAKE_ASSETS_${tag//[^A-Za-z0-9_]/_}"
    assets="${!assets_var:-}"
    for asset in ${assets}; do
      printf '%s\n' "${asset}"
    done
    ;;
  repos/Extra-Chill/homeboy/releases?per_page=100)
    printf '%b' "${FAKE_RELEASE_ROWS}"
    ;;
  *)
    printf 'unexpected gh endpoint: %s\n' "${endpoint}" >&2
    exit 1
    ;;
esac
SH
chmod +x "${FAKE_BIN}/gh"

run_resolver() {
  local version="$1"
  local output_file="$2"
  local log_file="$3"

  PATH="${FAKE_BIN}:${PATH}" \
  GITHUB_OUTPUT="${output_file}" \
  HOMEBOY_VERSION="${version}" \
  HOMEBOY_ASSET_RETRY_ATTEMPTS="2" \
  HOMEBOY_ASSET_RETRY_DELAY="0" \
  bash "${SCRIPT_DIR}/resolve-homeboy-version.sh" > "${log_file}" 2>&1
}

case "$(uname -s | tr '[:upper:]' '[:lower:]')-$(uname -m)" in
  linux-x86_64) TARGET="x86_64-unknown-linux-gnu" ;;
  linux-aarch64) TARGET="aarch64-unknown-linux-gnu" ;;
  darwin-x86_64) TARGET="x86_64-apple-darwin" ;;
  darwin-arm64) TARGET="aarch64-apple-darwin" ;;
  *)
    printf 'Unsupported test platform: %s-%s\n' "$(uname -s)" "$(uname -m)" >&2
    exit 1
    ;;
esac
ARCHIVE="homeboy-${TARGET}.tar.xz"
TAB=$'\t'

GITHUB_OUTPUT_FILE="${TMPDIR}/output-complete"
LOG_FILE="${TMPDIR}/log-complete"
FAKE_STATE_DIR="${TMPDIR}/state-complete" \
FAKE_ASSETS_v0_122_0="${ARCHIVE}" \
FAKE_RELEASE_ROWS="v0.122.0${TAB}${ARCHIVE}"$'\n' \
mkdir -p "${TMPDIR}/state-complete"

FAKE_STATE_DIR="${TMPDIR}/state-complete" \
FAKE_ASSETS_v0_122_0="${ARCHIVE}" \
FAKE_RELEASE_ROWS="v0.122.0${TAB}${ARCHIVE}"$'\n' \
run_resolver "latest" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}"
assert_equals "resolved-version=0.122.0" "$(cat "${GITHUB_OUTPUT_FILE}")" "latest keeps newest when asset exists"

GITHUB_OUTPUT_FILE="${TMPDIR}/output-scan"
LOG_FILE="${TMPDIR}/log-scan"
mkdir -p "${TMPDIR}/state-scan"
FAKE_STATE_DIR="${TMPDIR}/state-scan" \
FAKE_ASSETS_v0_122_0="" \
FAKE_RELEASE_ROWS="v0.122.0${TAB}other-platform.tar.xz"$'\n'"v0.121.0${TAB}other-platform.tar.xz"$'\n'"v0.120.0${TAB}${ARCHIVE}"$'\n'"v0.119.0${TAB}${ARCHIVE}"$'\n' \
run_resolver "latest" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}"
assert_equals "resolved-version=0.120.0" "$(cat "${GITHUB_OUTPUT_FILE}")" "latest scans newest-to-oldest for newest asset-bearing release"

GITHUB_OUTPUT_FILE="${TMPDIR}/output-missing"
LOG_FILE="${TMPDIR}/log-missing"
mkdir -p "${TMPDIR}/state-missing"
if FAKE_STATE_DIR="${TMPDIR}/state-missing" \
  FAKE_RELEASE_ROWS="v0.122.0${TAB}other-platform.tar.xz"$'\n'"v0.121.0${TAB}another-platform.tar.xz"$'\n' \
  run_resolver "latest" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}"; then
  printf 'FAIL: latest without a platform asset should fail\n'
  exit 1
fi
assert_contains "Could not find a stable Homeboy release with asset ${ARCHIVE}" "${LOG_FILE}" "latest missing asset fails clearly"

GITHUB_OUTPUT_FILE="${TMPDIR}/output-pinned"
LOG_FILE="${TMPDIR}/log-pinned"
mkdir -p "${TMPDIR}/state-pinned"
if FAKE_STATE_DIR="${TMPDIR}/state-pinned" \
  FAKE_ASSETS_v0_122_0="" \
  FAKE_RELEASE_ROWS="v0.121.0${TAB}${ARCHIVE}"$'\n' \
  run_resolver "0.122.0" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}"; then
  printf 'FAIL: pinned missing asset should fail\n'
  exit 1
fi
assert_contains "explicit versions do not fall back" "${LOG_FILE}" "pinned missing asset fails clearly"
assert_contains 'select(.draft == false and .prerelease == false)' "${SCRIPT_DIR}/resolve-homeboy-version.sh" "latest scans only stable releases"

printf 'All Homeboy version resolver checks passed.\n'
