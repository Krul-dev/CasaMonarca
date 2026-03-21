#!/usr/bin/env bash

set -euo pipefail

show_help() {
  cat <<'EOF'
Build the CasaMonarca Web app locally and upload the static files to the staging VPS.

Usage:
  ./scripts/deploy-staging.sh

Optional environment variables:
  REMOTE_HOST        VPS hostname or IP address
  REMOTE_USER        SSH user used to reach the VPS
  REMOTE_PORT        SSH port
  REMOTE_TARGET_DIR  Final static site directory on the VPS
  REMOTE_STAGE_DIR   Temporary upload directory on the VPS
  SSH_KEY            Optional SSH private key path

Defaults:
  REMOTE_HOST=204.168.173.236
  REMOTE_USER=casamonarca
  REMOTE_PORT=22
  REMOTE_TARGET_DIR=/home/casamonarca/public_html
  REMOTE_STAGE_DIR=/home/casamonarca/deployments/casamonarca-web

Notes:
  - The script preserves `.htaccess` and `.well-known` inside the target directory.
  - The script uploads a tarball with `scp` and promotes it on the VPS as the app user.
  - If `node_modules/` is missing, the script runs `npm ci` before building.
EOF
}

if [[ "${1:-}" == "--help" ]]; then
  show_help
  exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

REMOTE_HOST="${REMOTE_HOST:-204.168.173.236}"
REMOTE_USER="${REMOTE_USER:-casamonarca}"
REMOTE_PORT="${REMOTE_PORT:-22}"
REMOTE_TARGET_DIR="${REMOTE_TARGET_DIR:-/home/casamonarca/public_html}"
REMOTE_STAGE_DIR="${REMOTE_STAGE_DIR:-/home/casamonarca/deployments/casamonarca-web}"
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

require_command npm
require_command scp
require_command ssh
require_command tar
require_command mktemp

cd "${REPO_ROOT}"

if [[ ! -d node_modules ]]; then
  echo "Installing frontend dependencies with npm ci..."
  npm ci
fi

echo "Building frontend..."
npm run build

TMP_DIR="$(mktemp -d)"
ARCHIVE_PATH="${TMP_DIR}/casamonarca-web-dist.tgz"
REMOTE_ARCHIVE_PATH="${REMOTE_STAGE_DIR}/casamonarca-web-dist.tgz"

cleanup() {
  rm -rf "${TMP_DIR}"
}

trap cleanup EXIT

echo "Packing dist/..."
tar -C "${REPO_ROOT}/dist" -czf "${ARCHIVE_PATH}" .

echo "Creating remote staging directory..."
ssh "${SSH_OPTS[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p '${REMOTE_STAGE_DIR}'"

echo "Uploading build archive with scp..."
scp "${SSH_OPTS[@]}" "${ARCHIVE_PATH}" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_ARCHIVE_PATH}"

echo "Promoting build on the VPS..."
ssh -tt "${SSH_OPTS[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "\
mkdir -p '${REMOTE_TARGET_DIR}' && \
find '${REMOTE_TARGET_DIR}' -mindepth 1 -maxdepth 1 \
  ! -name '.htaccess' \
  ! -name '.well-known' \
  -exec rm -rf {} + && \
tar -xzf '${REMOTE_ARCHIVE_PATH}' -C '${REMOTE_TARGET_DIR}' && \
rm -f '${REMOTE_ARCHIVE_PATH}'"

echo "Deployment complete."
echo "Open: http://${REMOTE_HOST}/"
