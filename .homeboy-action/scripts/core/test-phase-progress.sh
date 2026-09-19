#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROGRESS="${ROOT_DIR}/scripts/core/phase-progress.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

set +e
GITHUB_REPOSITORY='Extra-Chill/homeboy' \
GITHUB_RUN_ID='1234' \
GITHUB_JOB='test' \
GITHUB_OUTPUT="${TMP_DIR}/output" \
GITHUB_STEP_SUMMARY="${TMP_DIR}/summary" \
HOMEBOY_CI_RESULTS_DIR="${TMP_DIR}/results" \
HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/phases.jsonl" \
HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS=1 \
HOMEBOY_ACTION_PHASE_BUDGET_SECONDS=1 \
HOMEBOY_ACTION_PHASE_ANNOTATIONS=verbose \
bash "${PROGRESS}" run command_execution -- bash -c 'sleep 2' >"${TMP_DIR}/run.log" 2>&1
exit_code=$?
set -e

if [ "${exit_code}" -ne 0 ]; then
  printf 'FAIL: delayed phase exits %s\n' "${exit_code}"
  exit 1
fi
if ! grep -q 'phase heartbeat.*command_execution running' "${TMP_DIR}/run.log"; then
  printf 'FAIL: delayed phase did not emit a heartbeat before completion\n'
  exit 1
fi
if ! grep -q 'phase budget exceeded.*Homeboy command runner' "${TMP_DIR}/run.log"; then
  printf 'FAIL: delayed phase did not name an owning subsystem after its budget\n'
  exit 1
fi

GITHUB_REPOSITORY='Extra-Chill/homeboy' \
GITHUB_RUN_ID='1234' \
GITHUB_JOB='test' \
GITHUB_OUTPUT="${TMP_DIR}/output" \
GITHUB_STEP_SUMMARY="${TMP_DIR}/summary" \
HOMEBOY_CI_RESULTS_DIR="${TMP_DIR}/results" \
HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/phases.jsonl" \
bash "${PROGRESS}" summary >/dev/null

if ! jq -e '.run_ref == "github://Extra-Chill/homeboy/actions/runs/1234#test" and .slowest_phases[0].phase == "command_execution"' "${TMP_DIR}/results/phase-progress.json" >/dev/null; then
  printf 'FAIL: phase artifact does not preserve the stable run ref and slowest phase\n'
  exit 1
fi
if ! grep -q 'Three slowest phases' "${TMP_DIR}/summary"; then
  printf 'FAIL: phase summary does not report the slowest phases\n'
  exit 1
fi

HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/bounded-liveness-phases.jsonl" \
HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS=1 \
HOMEBOY_ACTION_PHASE_BUDGET_SECONDS=1 \
bash "${PROGRESS}" run command_execution -- bash -c 'sleep 2' >"${TMP_DIR}/bounded-liveness.log" 2>&1
if ! grep -q '^Homeboy phase heartbeat::command_execution running' "${TMP_DIR}/bounded-liveness.log" \
  || ! grep -q '^Homeboy phase budget exceeded::command_execution exceeded its 1s budget; owner: Homeboy command runner' "${TMP_DIR}/bounded-liveness.log"; then
  printf 'FAIL: bounded mode did not retain plain liveness progress\n'
  exit 1
fi
if grep -q '^::' "${TMP_DIR}/bounded-liveness.log"; then
  printf 'FAIL: bounded liveness progress was emitted as a GitHub annotation\n'
  exit 1
fi

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/bin/sleep" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$$" > "${HEARTBEAT_SLEEP_PID_FILE}"
exec /bin/sleep "$@"
SH
chmod +x "${TMP_DIR}/bin/sleep"

HEARTBEAT_SLEEP_PID_FILE="${TMP_DIR}/heartbeat-sleep.pid" \
PATH="${TMP_DIR}/bin:${PATH}" \
HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/cleanup-phases.jsonl" \
HOMEBOY_ACTION_PHASE_HEARTBEAT_SECONDS=30 \
bash "${PROGRESS}" run command_execution -- bash -c 'while [ ! -s "$HEARTBEAT_SLEEP_PID_FILE" ]; do :; done' >/dev/null
heartbeat_sleep_pid="$(cat "${TMP_DIR}/heartbeat-sleep.pid")"
if kill -0 "${heartbeat_sleep_pid}" 2>/dev/null; then
  printf 'FAIL: phase finalization left heartbeat timer %s for runner orphan cleanup\n' "${heartbeat_sleep_pid}"
  kill "${heartbeat_sleep_pid}" 2>/dev/null || true
  exit 1
fi
printf 'PASS: phase finalization terminates its active heartbeat timer\n'

HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/bounded-phases.jsonl" \
bash "${PROGRESS}" run command_execution -- bash -c 'exit 1' >"${TMP_DIR}/bounded.log" 2>&1 || true
HOMEBOY_ACTION_PHASE_PROGRESS_FILE="${TMP_DIR}/bounded-phases.jsonl" \
bash "${PROGRESS}" run command_execution -- bash -c 'exit 1' >>"${TMP_DIR}/bounded.log" 2>&1 || true

if [ "$(grep -c '^::error title=Homeboy phase failed \[command_execution\]::' "${TMP_DIR}/bounded.log")" -ne 1 ]; then
  printf 'FAIL: repeated failed phase did not emit exactly one stable terminal annotation\n'
  exit 1
fi
if grep -q '^::\(notice\|warning\)' "${TMP_DIR}/bounded.log"; then
  printf 'FAIL: bounded mode emitted lifecycle progress annotations\n'
  exit 1
fi
if [ "$(jq -s '[.[] | select(.phase == "command_execution" and .status == "failed")] | length' "${TMP_DIR}/bounded-phases.jsonl")" -ne 2 ]; then
  printf 'FAIL: bounded mode did not retain complete failed phase progress\n'
  exit 1
fi
printf 'PASS: phase progress preserves verbose annotations, plain liveness, and bounded default failures\n'
