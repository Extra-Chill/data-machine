#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_file() {
  local path="$1"
  local label="$2"
  if [ ! -s "${path}" ]; then
    printf 'FAIL: %s missing at %s\n' "${label}" "${path}"
    exit 1
  fi
  printf 'PASS: %s\n' "${label}"
}

mkdir -p "${TMP_DIR}/bin" "${TMP_DIR}/workspace"
cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output)
      output="$2"
      shift 2
      ;;
    *)
      shift
      ;;
  esac
done
if [ -z "${output}" ]; then
  echo "missing --output" >&2
  exit 2
fi
mkdir -p "$(dirname "${output}")"
  printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"bench","success":true,"status":"succeeded","exit_code":0,"data":{"scenarios":[{"scenario":"noop","metrics":{"elapsed_ms":{"p50":1,"p95":2}}}]}}' > "${output}"
SH
chmod +x "${TMP_DIR}/bin/homeboy"

export PATH="${TMP_DIR}/bin:${PATH}"
export GITHUB_ACTION_PATH="${ROOT_DIR}"
export GITHUB_WORKSPACE="${TMP_DIR}/workspace"
export GITHUB_OUTPUT="${TMP_DIR}/github-output"
export GITHUB_ENV="${TMP_DIR}/github-env"
export GITHUB_REPOSITORY="Extra-Chill/homeboy-action"
export RESOLVED_COMMANDS="bench"
export COMPONENT_NAME="homeboy-action"
export BENCH_RIG="main,pr"
export BENCH_SCENARIO="noop"
export BENCH_RUNS="1"
export BENCH_ITERATIONS="2"

(cd "${TMP_DIR}/workspace" && bash "${ROOT_DIR}/scripts/core/run-homeboy-commands.sh")

assert_file "${TMP_DIR}/workspace/homeboy-ci-results/bench.json" "stable bench artifact"

# The comment summary now reads the same durable results path as every other
# consumer instead of a duplicate copy in HOMEBOY_OUTPUT_DIR, so assert the
# path it actually reads.
results_dir="$(grep '^HOMEBOY_CI_RESULTS_DIR=' "${TMP_DIR}/github-env" | cut -d= -f2-)"
: "${results_dir:="${TMP_DIR}/workspace/homeboy-ci-results"}"
assert_file "${results_dir}/bench.json" "comment-summary bench artifact source"

if ! grep -q '^results={"bench":"pass"}$' "${TMP_DIR}/github-output"; then
  printf 'FAIL: bench result was not recorded as pass\n'
  cat "${TMP_DIR}/github-output"
  exit 1
fi
printf 'PASS: bench result recorded\n'

printf 'Bench command runner checks passed.\n'
