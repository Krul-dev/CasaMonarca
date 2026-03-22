#!/usr/bin/env bash

set -euo pipefail

show_help() {
  cat <<'EOF'
Open an SSH session to the staging VPS and pull the latest API code there.

Usage:
  ./scripts/deploy-staging.sh

Optional environment variables:
  REMOTE_HOST      VPS hostname or IP address
  REMOTE_USER      SSH user used to reach the VPS
  REMOTE_PORT      SSH port
  REMOTE_APP_DIR   API repository path on the VPS
  REMOTE_BRANCH    Branch to pull on the VPS
  SSH_KEY          Optional SSH private key path

Defaults:
  REMOTE_HOST=204.168.173.236
  REMOTE_USER=casamonarca
  REMOTE_PORT=22
  REMOTE_APP_DIR=/home/casamonarca/apps/api/current
  REMOTE_BRANCH=main

Notes:
  - The remote repository itself must already be cloned on the VPS.
  - The remote `origin` should already be configured for SSH if you want SSH-based pulls there.
  - This script only updates the Git working tree. Composer, migrations, and cache clears stay manual for now.
EOF
}

if [[ "${1:-}" == "--help" ]]; then
  show_help
  exit 0
fi

REMOTE_HOST="${REMOTE_HOST:-204.168.173.236}"
REMOTE_USER="${REMOTE_USER:-casamonarca}"
REMOTE_PORT="${REMOTE_PORT:-22}"
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/home/casamonarca/apps/api/current}"
REMOTE_BRANCH="${REMOTE_BRANCH:-main}"
SSH_KEY="${SSH_KEY:-}"

SSH_OPTS=(-p "${REMOTE_PORT}")

if [[ -n "${SSH_KEY}" ]]; then
  SSH_OPTS+=(-i "${SSH_KEY}")
fi

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_command ssh

echo "Pulling ${REMOTE_BRANCH} on ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_APP_DIR} ..."

ssh -tt "${SSH_OPTS[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "\
cd '${REMOTE_APP_DIR}' && \
git fetch origin '${REMOTE_BRANCH}' && \
git checkout '${REMOTE_BRANCH}' && \
git pull --ff-only origin '${REMOTE_BRANCH}'"

echo "Remote Git pull complete."
