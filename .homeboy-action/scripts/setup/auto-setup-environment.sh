#!/usr/bin/env bash

# Auto-setup development environment based on extension type.
# Runs composer install for PHP projects, npm install for Node projects.
#
# Reads:
#   PORTABLE_EXTENSION — extension id (wordpress, rust, node)
#   PORTABLE_PHP       — resolved php version (may be empty)
#   PORTABLE_NODE      — resolved node version (may be empty)
#   AUTO_SETUP         — "true" (default) or "false" to skip
#
# PHP and Node binary setup is handled by action.yml composite steps
# (shivammathur/setup-php and actions/setup-node). This script handles
# dependency installation that those actions don't cover.

set -euo pipefail

if [ "${AUTO_SETUP:-true}" = "false" ]; then
  echo "Auto-setup disabled, skipping"
  exit 0
fi

EXTENSION="${PORTABLE_EXTENSION:-}"

# ── Composer install (PHP projects) ──

if [ -f "composer.json" ]; then
  if [ ! -d "vendor" ] || [ "composer.json" -nt "vendor/autoload.php" ] 2>/dev/null; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --no-progress 2>&1
    echo "Composer dependencies installed"
  else
    echo "Composer vendor/ is up to date, skipping install"
  fi
fi

# ── Extension setup ──

if [ -n "${EXTENSION}" ] && command -v homeboy &>/dev/null; then
  echo "Setting up Homeboy extension dependencies (${EXTENSION})..."
  # Extension installation may already have resolved a moving WP Codebox ref.
  # Reuse that commit when setup runs again so checkout and provenance agree.
  if [ -n "${WP_CODEBOX_SOURCE_SHA:-}" ]; then
    HOMEBOY_WP_CODEBOX_REF="${WP_CODEBOX_SOURCE_SHA}" homeboy extension setup "${EXTENSION}" 2>&1
  else
    homeboy extension setup "${EXTENSION}" 2>&1
  fi
fi

# ── npm install (Node projects) ──

if [ -f "package.json" ] && [ -n "${PORTABLE_NODE:-}" ]; then
  if [ ! -d "node_modules" ]; then
    echo "Installing Node dependencies..."
    if [ -f "package-lock.json" ]; then
      npm ci --no-audit --no-fund 2>&1
    elif [ -f "pnpm-lock.yaml" ]; then
      npx pnpm install --frozen-lockfile 2>&1
    elif [ -f "yarn.lock" ]; then
      yarn install --frozen-lockfile 2>&1
    else
      npm install --no-audit --no-fund 2>&1
    fi
    echo "Node dependencies installed"
  else
    echo "node_modules/ exists, skipping install"
  fi
fi

echo "Auto-setup complete"
