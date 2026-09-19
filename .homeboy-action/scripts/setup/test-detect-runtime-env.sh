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

TMPDIR="$(mktemp -d)"
trap 'rm -rf "${TMPDIR}"' EXIT

FAKE_BIN="${TMPDIR}/bin"
FAKE_EXTENSION="${TMPDIR}/extensions/wordpress"
mkdir -p "${FAKE_BIN}" "${FAKE_EXTENSION}"
printf '{"engines":{"node":">=18.12.0"}}\n' > "${FAKE_EXTENSION}/package.json"

# The fake mirrors core's real `component env` output shape:
# `.data.entity.runtimes.<id>.version` (RuntimeRequirementsConfig). The flat
# `.data.entity.php` shape this test used to stub was retired in core; the
# script kept reading it and silently detected nothing for every component.
#
# FAKE_COMPONENT_ENV_MODE selects the scenario:
#   ok        — success with php + node declared
#   php-only  — success with only php declared
#   empty     — success with no runtimes (component declares nothing)
#   fail      — non-zero exit with a parse error on stderr (contract break)
cat > "${FAKE_BIN}/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

if [ "$1" = "component" ] && [ "$2" = "env" ]; then
  case "${FAKE_COMPONENT_ENV_MODE:-ok}" in
    ok)
      printf '{"success":true,"data":{"entity":{"runtimes":{"php":{"version":"8.2","source":"component"},"node":{"version":"22","source":"component"}}}}}\n'
      exit 0
      ;;
    php-only)
      printf '{"success":true,"data":{"entity":{"runtimes":{"php":{"version":"8.4","source":"component"}}}}}\n'
      exit 0
      ;;
    empty)
      printf '{"success":true,"data":{"entity":{"command":"component.env","id":"demo"}}}\n'
      exit 0
      ;;
    fail)
      printf '{"success":false,"error":{"code":"validation.invalid_json","message":"Invalid JSON","details":{"error":"unknown field `php`, expected `runtimes` at line 1 column 6"}}}\n'
      printf 'Error: parse component env detector output for extension %s\n' "wordpress" >&2
      exit 1
      ;;
  esac
fi

if [ "$1" = "extension" ] && [ "$2" = "show" ]; then
  printf '{"success":true,"data":{"extension":{"path":"%s"}}}\n' "${FAKE_EXTENSION_PATH}"
  exit 0
fi

printf 'unexpected fake homeboy invocation: %s\n' "$*" >&2
exit 1
SH
chmod +x "${FAKE_BIN}/homeboy"

run_detect() {
  local mode="$1"
  local env_file="$2"
  local output_file="$3"
  local log_file="$4"
  shift 4

  PATH="${FAKE_BIN}:${PATH}" \
  FAKE_EXTENSION_PATH="${FAKE_EXTENSION}" \
  FAKE_COMPONENT_ENV_MODE="${mode}" \
  GITHUB_ENV="${env_file}" \
  GITHUB_OUTPUT="${output_file}" \
  COMPONENT_DIR="${TMPDIR}" \
  PORTABLE_EXTENSION="wordpress" \
  DEFAULT_EXTENSION_NODE_VERSION="24" \
  "$@" \
  bash "${SCRIPT_DIR}/detect-runtime-env.sh" > "${log_file}" 2>&1
}

# 1. Canonical runtimes shape: php and node both read from .runtimes.<id>.version.
GITHUB_ENV_FILE="${TMPDIR}/env-ok"
GITHUB_OUTPUT_FILE="${TMPDIR}/output-ok"
LOG_FILE="${TMPDIR}/log-ok"
run_detect ok "${GITHUB_ENV_FILE}" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}" env

assert_equals "PORTABLE_PHP=8.2" "$(grep '^PORTABLE_PHP=' "${GITHUB_ENV_FILE}")" "reads php from runtimes shape"
assert_equals "PORTABLE_NODE=22" "$(grep '^PORTABLE_NODE=' "${GITHUB_ENV_FILE}")" "reads node from runtimes shape"
assert_equals "portable-php=8.2" "$(grep '^portable-php=' "${GITHUB_OUTPUT_FILE}")" "writes portable-php output"

# 2. Only php declared: node falls back to the extension requirement.
GITHUB_ENV_FILE="${TMPDIR}/env-php-only"
GITHUB_OUTPUT_FILE="${TMPDIR}/output-php-only"
LOG_FILE="${TMPDIR}/log-php-only"
run_detect php-only "${GITHUB_ENV_FILE}" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}" env

assert_equals "PORTABLE_PHP=8.4" "$(grep '^PORTABLE_PHP=' "${GITHUB_ENV_FILE}")" "detects component PHP"
assert_equals "PORTABLE_NODE=24" "$(grep '^PORTABLE_NODE=' "${GITHUB_ENV_FILE}")" "uses extension-required Node fallback"
assert_equals "portable-node=24" "$(grep '^portable-node=' "${GITHUB_OUTPUT_FILE}")" "writes portable-node output"

if ! grep -q 'node: 24 (required by wordpress extension setup)' "${LOG_FILE}"; then
  printf 'FAIL: extension Node requirement is explained in log\n'
  exit 1
fi
printf 'PASS: extension Node requirement is explained in log\n'

# 3. Component declares nothing: php is legitimately skipped and the log says
#    what the run will actually execute on.
GITHUB_ENV_FILE="${TMPDIR}/env-empty"
GITHUB_OUTPUT_FILE="${TMPDIR}/output-empty"
LOG_FILE="${TMPDIR}/log-empty"
run_detect empty "${GITHUB_ENV_FILE}" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}" env

assert_equals "PORTABLE_PHP=" "$(grep '^PORTABLE_PHP=' "${GITHUB_ENV_FILE}")" "no php when component declares none"
if ! grep -q 'php:  skip (component declares none' "${LOG_FILE}"; then
  printf 'FAIL: php skip is explained as a component decision\nlog:\n%s\n' "$(cat "${LOG_FILE}")"
  exit 1
fi
printf 'PASS: php skip is explained as a component decision\n'

# 4. Detector contract break: the step must fail, not fall through to skip.
GITHUB_ENV_FILE="${TMPDIR}/env-fail"
GITHUB_OUTPUT_FILE="${TMPDIR}/output-fail"
LOG_FILE="${TMPDIR}/log-fail"
set +e
run_detect fail "${GITHUB_ENV_FILE}" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}" env
fail_exit=$?
set -e

if [ "${fail_exit}" -eq 0 ]; then
  printf 'FAIL: component env failure must fail the step\nlog:\n%s\n' "$(cat "${LOG_FILE}")"
  exit 1
fi
printf 'PASS: component env failure fails the step (exit %s)\n' "${fail_exit}"

if ! grep -q '::error::homeboy component env failed' "${LOG_FILE}"; then
  printf 'FAIL: failure is reported as a workflow error annotation\nlog:\n%s\n' "$(cat "${LOG_FILE}")"
  exit 1
fi
printf 'PASS: failure is reported as a workflow error annotation\n'

if ! grep -q 'expected `runtimes`' "${LOG_FILE}"; then
  printf 'FAIL: core parse error is surfaced in the log\nlog:\n%s\n' "$(cat "${LOG_FILE}")"
  exit 1
fi
printf 'PASS: core parse error is surfaced in the log\n'

if [ -f "${GITHUB_ENV_FILE}" ] && grep -q '^PORTABLE_PHP=' "${GITHUB_ENV_FILE}"; then
  printf 'FAIL: no runtime versions must be published after a detector failure\n'
  exit 1
fi
printf 'PASS: no runtime versions published after a detector failure\n'

# 5. Action input overrides still win over detection.
GITHUB_ENV_FILE="${TMPDIR}/env-input"
GITHUB_OUTPUT_FILE="${TMPDIR}/output-input"
LOG_FILE="${TMPDIR}/log-input"
run_detect ok "${GITHUB_ENV_FILE}" "${GITHUB_OUTPUT_FILE}" "${LOG_FILE}" env NODE_INPUT="20" PHP_INPUT="8.1"

assert_equals "PORTABLE_NODE=20" "$(grep '^PORTABLE_NODE=' "${GITHUB_ENV_FILE}")" "node input overrides detection"
assert_equals "PORTABLE_PHP=8.1" "$(grep '^PORTABLE_PHP=' "${GITHUB_ENV_FILE}")" "php input overrides detection"
