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
  SYNC_STRATEGY    Git sync mode: ff-only or reset
  RESET_PERMISSIONS When set to 1, normalize remote file modes after pull
  GITHUB_USERNAME  GitHub username used for HTTPS remotes on the VPS
  GITHUB_TOKEN_FILE Path to a GitHub token file on the VPS
  SSH_KEY          Optional SSH private key path

Defaults:
  REMOTE_HOST=204.168.173.236
  REMOTE_USER=casamonarca
  REMOTE_PORT=22
  REMOTE_APP_DIR=/home/casamonarca/apps/api/current
  REMOTE_BRANCH=main
  SYNC_STRATEGY=ff-only
  RESET_PERMISSIONS=1

Notes:
  - The remote repository itself must already be cloned on the VPS.
  - If `origin` uses SSH on the VPS, leave `GITHUB_USERNAME` and `GITHUB_TOKEN_FILE` unset.
  - If `origin` uses HTTPS on the VPS, point `GITHUB_TOKEN_FILE` to a token file on the VPS.
  - `SYNC_STRATEGY=reset` force-aligns the VPS checkout to `origin/$REMOTE_BRANCH`.
  - This script only updates the Git working tree. Composer, migrations, and cache clears stay manual for now.
  - Ownership and SELinux labels are not changed by this script.
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
SYNC_STRATEGY="${SYNC_STRATEGY:-ff-only}"
RESET_PERMISSIONS="${RESET_PERMISSIONS:-1}"
GITHUB_USERNAME="${GITHUB_USERNAME:-}"
GITHUB_TOKEN_FILE="${GITHUB_TOKEN_FILE:-}"
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

ssh -T "${SSH_OPTS[@]}" "${REMOTE_USER}@${REMOTE_HOST}" bash -s -- \
  "${REMOTE_APP_DIR}" \
  "${REMOTE_BRANCH}" \
  "${SYNC_STRATEGY}" \
  "${RESET_PERMISSIONS}" \
  "${GITHUB_USERNAME}" \
  "${GITHUB_TOKEN_FILE}" <<'EOF'
set -euo pipefail

REMOTE_APP_DIR="$1"
REMOTE_BRANCH="$2"
SYNC_STRATEGY="$3"
RESET_PERMISSIONS="$4"
GITHUB_USERNAME="${5-}"
GITHUB_TOKEN_FILE="${6-}"

run_git() {
  if [[ -n "${GITHUB_USERNAME}" && -n "${GITHUB_TOKEN_FILE}" ]]; then
    if [[ ! -r "${GITHUB_TOKEN_FILE}" ]]; then
      echo "Token file not readable: ${GITHUB_TOKEN_FILE}" >&2
      exit 1
    fi

    local auth_b64
    auth_b64="$(printf '%s' "${GITHUB_USERNAME}:$(<"${GITHUB_TOKEN_FILE}")" | base64 | tr -d '\n')"
    git -c "http.extraheader=AUTHORIZATION: Basic ${auth_b64}" "$@"
  else
    git "$@"
  fi
}

cd "${REMOTE_APP_DIR}"
run_git fetch origin "${REMOTE_BRANCH}"

if git show-ref --verify --quiet "refs/heads/${REMOTE_BRANCH}"; then
  git checkout "${REMOTE_BRANCH}"

  case "${SYNC_STRATEGY}" in
    ff-only)
      run_git pull --ff-only origin "${REMOTE_BRANCH}"
      ;;
    reset)
      git reset --hard "FETCH_HEAD"
      ;;
    *)
      echo "Unsupported SYNC_STRATEGY: ${SYNC_STRATEGY}" >&2
      exit 1
      ;;
  esac
else
  git checkout -b "${REMOTE_BRANCH}" FETCH_HEAD
fi

if [[ "${RESET_PERMISSIONS}" == "1" ]]; then
  find "${REMOTE_APP_DIR}" -type d -exec chmod 755 {} +
  find "${REMOTE_APP_DIR}" -type f -exec chmod 644 {} +

  if [[ -f "${REMOTE_APP_DIR}/artisan" ]]; then
    chmod 755 "${REMOTE_APP_DIR}/artisan"
  fi

  if [[ -d "${REMOTE_APP_DIR}/scripts" ]]; then
    find "${REMOTE_APP_DIR}/scripts" -type f -name '*.sh' -exec chmod 755 {} +
  fi

  if [[ -d "${REMOTE_APP_DIR}/storage" ]]; then
    find "${REMOTE_APP_DIR}/storage" -type d -exec chmod 755 {} +
    find "${REMOTE_APP_DIR}/storage" -type f -exec chmod 644 {} +
  fi

  if [[ -d "${REMOTE_APP_DIR}/bootstrap/cache" ]]; then
    find "${REMOTE_APP_DIR}/bootstrap/cache" -type d -exec chmod 755 {} +
    find "${REMOTE_APP_DIR}/bootstrap/cache" -type f -exec chmod 644 {} +
  fi

  if [[ -f "${REMOTE_APP_DIR}/.env" ]]; then
    chmod 600 "${REMOTE_APP_DIR}/.env"
  fi
fi
EOF

echo "Remote Git pull complete."
