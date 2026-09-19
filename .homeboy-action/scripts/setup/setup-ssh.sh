#!/usr/bin/env bash
#
# Configure SSH for fleet/deploy commands.
#
# Supports two modes:
#   1. SSH_KEY provided: writes the key, starts ssh-agent, adds known_hosts
#   2. SSH_KEY empty: assumes SSH is already configured (e.g. webfactory/ssh-agent)
#
# Env vars:
#   SSH_KEY         — SSH private key content (optional)
#   SSH_KNOWN_HOSTS — extra known_hosts entries (optional)
#
# Outputs (GITHUB_OUTPUT):
#   ssh-configured — true|false

set -euo pipefail

SSH_KEY="${SSH_KEY:-}"
SSH_KNOWN_HOSTS="${SSH_KNOWN_HOSTS:-}"

SSH_DIR="${HOME}/.ssh"
mkdir -p "${SSH_DIR}"
chmod 700 "${SSH_DIR}"

if [ -n "${SSH_KEY}" ]; then
  echo "Configuring SSH from provided key..."

  # Write the private key
  KEY_FILE="${SSH_DIR}/id_ed25519"
  printf '%s\n' "${SSH_KEY}" > "${KEY_FILE}"
  chmod 600 "${KEY_FILE}"

  # Ensure the key has a trailing newline (some secrets managers strip it)
  if [ "$(tail -c 1 "${KEY_FILE}" | wc -l)" -eq 0 ]; then
    printf '\n' >> "${KEY_FILE}"
  fi

  # Start ssh-agent if not already running
  if [ -z "${SSH_AUTH_SOCK:-}" ]; then
    eval "$(ssh-agent -s)"
    echo "SSH_AUTH_SOCK=${SSH_AUTH_SOCK}" >> "${GITHUB_ENV}"
    echo "SSH_AGENT_PID=${SSH_AGENT_PID}" >> "${GITHUB_ENV}"
  fi

  # Add the key to the agent
  ssh-add "${KEY_FILE}"

  # Configure known_hosts — start with GitHub and common providers
  KNOWN_HOSTS_FILE="${SSH_DIR}/known_hosts"
  touch "${KNOWN_HOSTS_FILE}"
  chmod 644 "${KNOWN_HOSTS_FILE}"

  # Add GitHub's SSH keys (always useful in CI)
  ssh-keyscan -t ed25519,rsa github.com >> "${KNOWN_HOSTS_FILE}" 2>/dev/null || true

  # Add user-provided known_hosts entries
  if [ -n "${SSH_KNOWN_HOSTS}" ]; then
    printf '%s\n' "${SSH_KNOWN_HOSTS}" >> "${KNOWN_HOSTS_FILE}"
  fi

  # Configure SSH to accept new host keys for non-GitHub hosts
  # This is a CI-specific tradeoff — we trust the user's servers
  SSH_CONFIG_FILE="${SSH_DIR}/config"
  if [ ! -f "${SSH_CONFIG_FILE}" ] || ! grep -q "StrictHostKeyChecking" "${SSH_CONFIG_FILE}" 2>/dev/null; then
    cat >> "${SSH_CONFIG_FILE}" << 'SSHCONFIG'

# Added by homeboy-action for fleet/deploy commands
Host *
  StrictHostKeyChecking accept-new
  ServerAliveInterval 60
  ServerAliveCountMax 3
SSHCONFIG
    chmod 600 "${SSH_CONFIG_FILE}"
  fi

  echo "SSH configured: key loaded, agent running, known_hosts populated"
  echo "ssh-configured=true" >> "${GITHUB_OUTPUT}"

elif [ -n "${SSH_AUTH_SOCK:-}" ]; then
  echo "SSH agent already running (SSH_AUTH_SOCK=${SSH_AUTH_SOCK})"

  # Still add user-provided known_hosts if any
  if [ -n "${SSH_KNOWN_HOSTS}" ]; then
    KNOWN_HOSTS_FILE="${SSH_DIR}/known_hosts"
    touch "${KNOWN_HOSTS_FILE}"
    printf '%s\n' "${SSH_KNOWN_HOSTS}" >> "${KNOWN_HOSTS_FILE}"
    echo "Added extra known_hosts entries"
  fi

  echo "ssh-configured=true" >> "${GITHUB_OUTPUT}"

else
  echo "No SSH key provided and no SSH agent detected"
  echo "Fleet/deploy commands requiring SSH will fail unless SSH is configured by a prior step"
  echo "ssh-configured=false" >> "${GITHUB_OUTPUT}"
fi
