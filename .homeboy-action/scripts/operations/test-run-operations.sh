#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/bin/homeboy" <<'SH'
#!/usr/bin/env bash

output=""
command=()
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) command+=("$1"); shift ;;
  esac
done
mkdir -p "$(dirname "${output}")"
# Real homeboy reports only the top-level command in the envelope, so the fake
# mirrors that contract instead of echoing the full command string back.
command_root="${command[0]}"
case "${FAKE_OPERATION_MODE:-valid}" in
  valid) printf '{"schema":"homeboy/command-result/v3","command":"%s","success":true,"status":"succeeded","exit_code":0,"data":{}}\n' "${command_root}" > "${output}" ;;
  missing) : ;;
  malformed) printf '%s\n' '{}' > "${output}" ;;
  wrong-command) printf '%s\n' '{"schema":"homeboy/command-result/v3","command":"deploy","success":true,"status":"succeeded","exit_code":0,"data":{}}' > "${output}" ;;
  timeout)
    trap '' TERM
    (trap '' TERM; while :; do sleep 1; done) &
    printf 'operations output retained\n'
    while :; do sleep 1; done
    ;;
esac
SH
chmod +x "${TMP_DIR}/bin/homeboy"

run_operation() {
  local command="$1" mode="$2" output_file="$3"
  set +e
  PATH="${TMP_DIR}/bin:${PATH}" GITHUB_ACTION_PATH="${ROOT_DIR}" GITHUB_OUTPUT="${output_file}" OPERATIONS_COMMANDS="${command}" FAKE_OPERATION_MODE="${mode}" HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS=1 HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS=1 bash "${ROOT_DIR}/scripts/operations/run-operations.sh" >"${output_file}.log" 2>&1
  local exit_code=$?
  set -e
  printf '%s\n' "${exit_code}"
}

for command in 'fleet status fixture' 'deploy fixture --all' 'release fixture'; do
  output_file="${TMP_DIR}/$(printf '%s' "${command}" | tr ' ' '-')"
  if [ "$(run_operation "${command}" valid "${output_file}")" -ne 0 ] || ! grep -Fq "\"${command}\":\"pass\"" "${output_file}"; then
    printf 'FAIL: valid %s operation did not pass exact envelope validation\n' "${command}"
    exit 1
  fi
done
printf 'PASS: fleet, deploy, and release operations validate exact v3 envelopes\n'

for mode in missing malformed wrong-command; do
  output_file="${TMP_DIR}/${mode}"
  if [ "$(run_operation 'fleet status fixture' "${mode}" "${output_file}")" -ne 1 ] || ! grep -q '"fleet status fixture":"fail"' "${output_file}" || ! grep -q 'did not write valid structured output' "${output_file}.log"; then
    printf 'FAIL: zero-exit %s operation envelope did not fail closed\n' "${mode}"
    exit 1
  fi
done
printf 'PASS: missing, malformed, and wrong-command operation envelopes fail closed with retained logs\n'

output_file="${TMP_DIR}/timeout"
if [ "$(run_operation 'fleet status fixture' timeout "${output_file}")" -ne 1 ] || ! grep -q '"fleet status fixture":"timeout"' "${output_file}" || ! grep -q 'operations output retained' "${output_file}.log"; then
  printf 'FAIL: timed out operation did not retain logs and classify timeout\n'
  exit 1
fi
printf 'PASS: timed out operation preserves retained logs and timeout classification\n'
