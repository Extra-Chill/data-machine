#!/usr/bin/env bash

# Runs every action shell test script and reports one aggregate result.
#
# The action's tests are plain bash — no cargo, no network, no homeboy binary
# (each script stubs its own fake). They are cheap enough to gate every PR and
# every release on, which is the point: regressions in this repo re-point the
# floating `v2` tag and break every consumer at once.
#
# Each script runs under a timeout so a hung supervisor test cannot wedge CI,
# and all scripts run even after one fails so a single break does not hide the
# rest.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

per_test_timeout="${HOMEBOY_ACTION_TEST_TIMEOUT_SECONDS:-600}"

# Both suffixes are collected on purpose. `test-differential-gate.py` sat in
# this tree unreferenced by any runner or workflow, so it never executed once;
# its assertions had silently drifted to encode the exact `pass`-from-two-
# failures behaviour that Extra-Chill/homeboy#10657 reports, and nothing caught
# it. A test file that no runner picks up is indistinguishable from no test.
mapfile -t tests < <(find scripts -mindepth 2 -maxdepth 2 \( -name 'test-*.sh' -o -name 'test-*.py' \) -type f | sort)

if [ "${#tests[@]}" -eq 0 ]; then
  echo "::error::No action test scripts found under scripts/*/test-*.{sh,py}"
  exit 1
fi

passed=0
failed_tests=()

for test in "${tests[@]}"; do
  echo "::group::${test}"
  start="$(date +%s)"
  case "${test}" in
    *.py) timeout "${per_test_timeout}" python3 "${test}" ;;
    *) timeout "${per_test_timeout}" bash "${test}" ;;
  esac
  status=$?
  elapsed=$(( $(date +%s) - start ))
  echo "::endgroup::"

  if [ "${status}" -eq 0 ]; then
    passed=$((passed + 1))
    printf 'PASS  %s (%ss)\n' "${test}" "${elapsed}"
  elif [ "${status}" -eq 124 ]; then
    failed_tests+=("${test}")
    echo "::error::${test} timed out after ${per_test_timeout}s"
  else
    failed_tests+=("${test}")
    echo "::error::${test} failed (exit ${status})"
  fi
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
printf '  Action shell tests: %s passed, %s failed, %s total\n' \
  "${passed}" "${#failed_tests[@]}" "${#tests[@]}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ "${#failed_tests[@]}" -ne 0 ]; then
  for test in "${failed_tests[@]}"; do
    printf '  FAILED  %s\n' "${test}"
  done
  exit 1
fi
