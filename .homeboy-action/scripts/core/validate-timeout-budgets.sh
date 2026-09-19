#!/usr/bin/env bash

set -euo pipefail

test_timeout="${HOMEBOY_TEST_TIMEOUT_SECONDS:?HOMEBOY_TEST_TIMEOUT_SECONDS is required}"
execution_timeout="${HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS:?HOMEBOY_ACTION_EXECUTION_TIMEOUT_SECONDS is required}"
cleanup_timeout="${HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS:?HOMEBOY_ACTION_CLEANUP_TIMEOUT_SECONDS is required}"

for value in "${test_timeout}" "${execution_timeout}" "${cleanup_timeout}"; do
  if ! [[ "${value}" =~ ^[1-9][0-9]*$ ]]; then
    echo "::error::Timeout budgets must be positive integer seconds."
    exit 1
  fi
done

if [ $((test_timeout + cleanup_timeout)) -ge "${execution_timeout}" ]; then
  echo "::error::test-timeout-seconds (${test_timeout}) plus cleanup-timeout-seconds (${cleanup_timeout}) must remain below execution-timeout-seconds (${execution_timeout}) so Homeboy can publish structured timeout evidence before the outer process-group backstop."
  exit 1
fi
