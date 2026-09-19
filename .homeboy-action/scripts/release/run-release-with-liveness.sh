#!/usr/bin/env bash
#
# Run release planning or execution with the same bounded containment and phase
# evidence used by other long-running Homeboy commands.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CORE_DIR="${SCRIPT_DIR}/../core"

if [ "${RELEASE_DRY_RUN:-false}" = "true" ]; then
  phase="release_planning"
  label="homeboy release planning"
else
  phase="release_execution"
  label="homeboy release execution"
fi

log_dir="${RUNNER_TEMP:-/tmp}"
mkdir -p "${log_dir}"
log_file="$(mktemp "${log_dir}/homeboy-action-${phase}.XXXXXX.log")"

set +e
bash "${CORE_DIR}/phase-progress.sh" run "${phase}" -- \
  bash "${CORE_DIR}/run-with-liveness-timeout.sh" \
    --log-file "${log_file}" \
    "${label}" bash "${SCRIPT_DIR}/run-release.sh"
exit_code=$?
set -e

# The command writes directly to a retained file so a descendant holding stdout
# cannot wedge finalization. Print a bounded diagnostic copy for every outcome.
echo "::group::Retained ${label} log"
if [ -s "${log_file}" ]; then
  tail -n 200 "${log_file}"
else
  echo "::notice::${label} produced no command output."
fi
echo "::endgroup::"

exit "${exit_code}"
