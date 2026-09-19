#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE_DIR="${ROOT_DIR}/fixtures/portable-subdirectory"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin" "${TMP_DIR}/workspace"

(
  cd "${FIXTURE_DIR}"
  COMPONENT_NAME='packages/gutenberg' \
  EXTENSION_INPUT='' \
  GITHUB_ENV="${TMP_DIR}/github-env" \
  GITHUB_OUTPUT="${TMP_DIR}/config-output" \
  bash "${ROOT_DIR}/scripts/setup/read-portable-config.sh"
)

grep -Fxq 'PORTABLE_ID=wp-native-gutenberg' "${TMP_DIR}/github-env"
grep -Fxq 'COMPONENT_DIR=packages/gutenberg' "${TMP_DIR}/github-env"
grep -Fxq 'portable-id=wp-native-gutenberg' "${TMP_DIR}/config-output"
grep -Fxq 'component-dir=packages/gutenberg' "${TMP_DIR}/config-output"

cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "${HOMEBOY_ARGS_FILE}"

output=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done

mkdir -p "$(dirname "${output}")"
printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"review","success":true,"status":"succeeded","exit_code":0,"data":{"component_id":"wp-native-gutenberg"}}' > "${output}"
SH
chmod +x "${TMP_DIR}/bin/homeboy"

set -a
source "${TMP_DIR}/github-env"
set +a

(
  cd "${FIXTURE_DIR}"
  PATH="${TMP_DIR}/bin:${PATH}" \
  GITHUB_ACTION_PATH="${ROOT_DIR}" \
  GITHUB_WORKSPACE="${TMP_DIR}/workspace" \
  GITHUB_OUTPUT="${TMP_DIR}/command-output" \
  GITHUB_ENV="${TMP_DIR}/command-env" \
  HOMEBOY_ARGS_FILE="${TMP_DIR}/homeboy-args" \
  RESOLVED_COMMANDS='review build' \
  COMPONENT_NAME='packages/gutenberg' \
  GITHUB_ACTIONS='false' \
  bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh"
)

expected="--output ${TMP_DIR}/workspace/homeboy-ci-results/review-build.json review build wp-native-gutenberg --path ${FIXTURE_DIR}/packages/gutenberg"
actual="$(cat "${TMP_DIR}/homeboy-args")"
if [ "${actual}" != "${expected}" ]; then
  printf 'FAIL: subdirectory review command does not separate identity from path\nexpected: %s\nactual:   %s\n' "${expected}" "${actual}"
  exit 1
fi

if [ "$(grep -Fc 'COMPONENT_NAME: ${{ steps.read-config.outputs.portable-id }}' "${ROOT_DIR}/action.yml")" -ne 6 ]; then
  printf 'FAIL: every downstream identity consumer must receive the resolved portable ID\n'
  exit 1
fi

if [ "$(grep -Fc 'COMPONENT_NAME: ${{ inputs.component }}' "${ROOT_DIR}/action.yml")" -ne 1 ]; then
  printf 'FAIL: raw component input must only be used to locate portable configuration\n'
  exit 1
fi

grep -Fq 'PORTABLE_ID_VALUE: ${{ steps.read-config.outputs.portable-id }}' "${ROOT_DIR}/action.yml"
printf 'PASS: subdirectory components use portable identity and component directory metadata\n'
