#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="${root}/scripts/setup/auto-setup-environment.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

mkdir -p "${tmp}/bin" "${tmp}/remote" "${tmp}/seed"
git -C "${tmp}/remote" init -q --bare
git -C "${tmp}/seed" init -q
git -C "${tmp}/seed" config user.email test@example.com
git -C "${tmp}/seed" config user.name 'Test User'
git -C "${tmp}/seed" remote add origin "${tmp}/remote"
printf 'first\n' > "${tmp}/seed/source"
git -C "${tmp}/seed" add source
git -C "${tmp}/seed" commit -qm first
pinned_sha="$(git -C "${tmp}/seed" rev-parse HEAD)"
git -C "${tmp}/seed" branch -M main
git -C "${tmp}/seed" push -q -u origin main

# Advance the moving ref after setup resolved its build provenance identity.
printf 'second\n' > "${tmp}/seed/source"
git -C "${tmp}/seed" commit -qam second
moving_sha="$(git -C "${tmp}/seed" rev-parse HEAD)"
git -C "${tmp}/seed" push -q origin main

cat > "${tmp}/bin/homeboy" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[ "$*" = 'extension setup wordpress' ]
git clone -q "${WP_CODEBOX_TEST_SOURCE}" "${WP_CODEBOX_TEST_CHECKOUT}"
git -C "${WP_CODEBOX_TEST_CHECKOUT}" checkout -q "${HOMEBOY_WP_CODEBOX_REF:-main}"
head_sha="$(git -C "${WP_CODEBOX_TEST_CHECKOUT}" rev-parse HEAD)"
printf 'ref=%s\nhead=%s\nprovenance=%s\n' \
  "${HOMEBOY_WP_CODEBOX_REF:-main}" "${head_sha}" "${WP_CODEBOX_SOURCE_SHA:-}" > "${WP_CODEBOX_TEST_LOG}"
[ "${head_sha}" = "${WP_CODEBOX_SOURCE_SHA:-}" ]
STUB
chmod +x "${tmp}/bin/homeboy"

PATH="${tmp}/bin:${PATH}" \
  PORTABLE_EXTENSION=wordpress \
  WP_CODEBOX_SOURCE_SHA="${pinned_sha}" \
  WP_CODEBOX_TEST_SOURCE="${tmp}/remote" \
  WP_CODEBOX_TEST_CHECKOUT="${tmp}/checkout" \
  WP_CODEBOX_TEST_LOG="${tmp}/setup.log" \
  bash "${script}" >/dev/null

grep -Fxq "ref=${pinned_sha}" "${tmp}/setup.log"
grep -Fxq "head=${pinned_sha}" "${tmp}/setup.log"
grep -Fxq "provenance=${pinned_sha}" "${tmp}/setup.log"
if grep -Fq "${moving_sha}" "${tmp}/setup.log"; then
  echo 'FAIL: setup retry checked out the advanced moving ref' >&2
  exit 1
fi

echo 'Auto-setup environment tests passed'
