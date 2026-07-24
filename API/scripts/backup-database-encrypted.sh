#!/usr/bin/env bash

set -euo pipefail

show_help() {
  cat <<'EOF'
Create an authenticated, encrypted MySQL logical backup using an age public key.

Required environment variables:
  DB_BACKUP_DATABASE       Database to dump
  DB_BACKUP_AGE_RECIPIENT  age public recipient (starts with age1)
  MYSQL_DEFAULTS_FILE      Read-only MySQL client options file outside the repository

Optional environment variables:
  DB_BACKUP_DIR            Destination directory (default: storage/app/backups/database)
  DB_BACKUP_PREFIX         Filename prefix (default: database)
  DB_BACKUP_RETENTION_DAYS Delete older encrypted backups and checksums when set

The MySQL options file should be owned by the deployment user, have mode 600,
and contain a [client] section with host, port, user, and password. Keep the age
private identity off the application server.
EOF
}

if [[ "${1:-}" == "--help" ]]; then
  show_help
  exit 0
fi

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_environment() {
  local name="$1"

  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: ${name}" >&2
    exit 1
  fi
}

require_command age
require_command gzip
require_command mysqldump
require_command sha256sum
require_command stat

require_environment DB_BACKUP_DATABASE
require_environment DB_BACKUP_AGE_RECIPIENT
require_environment MYSQL_DEFAULTS_FILE

if [[ ! -r "${MYSQL_DEFAULTS_FILE}" ]]; then
  echo "MySQL options file is not readable: ${MYSQL_DEFAULTS_FILE}" >&2
  exit 1
fi

mysql_options_mode="$(stat -c '%a' "${MYSQL_DEFAULTS_FILE}")"

if (( 10#${mysql_options_mode} % 100 != 0 )); then
  echo "MySQL options file must not be accessible by group or other users (expected mode 600 or 400)." >&2
  exit 1
fi

backup_dir="${DB_BACKUP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/storage/app/backups/database}"
backup_prefix="${DB_BACKUP_PREFIX:-database}"
retention_days="${DB_BACKUP_RETENTION_DAYS:-}"

if [[ ! "${backup_prefix}" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "DB_BACKUP_PREFIX may only contain letters, numbers, dots, underscores, and hyphens." >&2
  exit 1
fi

if [[ -n "${retention_days}" && ! "${retention_days}" =~ ^[0-9]+$ ]]; then
  echo "DB_BACKUP_RETENTION_DAYS must be a non-negative integer." >&2
  exit 1
fi

umask 077
mkdir -p "${backup_dir}"

timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
backup_path="${backup_dir}/${backup_prefix}-${timestamp}.sql.gz.age"
checksum_path="${backup_path}.sha256"

if [[ -e "${backup_path}" || -e "${checksum_path}" ]]; then
  echo "A backup already exists for timestamp ${timestamp}; refusing to overwrite it." >&2
  exit 1
fi

temporary_backup="$(mktemp "${backup_dir}/.${backup_prefix}-${timestamp}.XXXXXX.age")"
temporary_checksum="$(mktemp "${backup_dir}/.${backup_prefix}-${timestamp}.XXXXXX.sha256")"

cleanup() {
  rm -f "${temporary_backup}" "${temporary_checksum}"
}

trap cleanup EXIT

mysqldump \
  --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  "${DB_BACKUP_DATABASE}" \
  | gzip -9 \
  | age --recipient "${DB_BACKUP_AGE_RECIPIENT}" --output "${temporary_backup}"

read -r backup_checksum _ < <(sha256sum "${temporary_backup}")
printf '%s  %s\n' "${backup_checksum}" "$(basename "${backup_path}")" > "${temporary_checksum}"

mv "${temporary_backup}" "${backup_path}"
mv "${temporary_checksum}" "${checksum_path}"

if [[ -n "${retention_days}" ]]; then
  find "${backup_dir}" \
    -maxdepth 1 \
    -type f \
    \( -name "${backup_prefix}-*.sql.gz.age" -o -name "${backup_prefix}-*.sql.gz.age.sha256" \) \
    -mtime "+${retention_days}" \
    -delete
fi

echo "Encrypted database backup created: ${backup_path}"
echo "Checksum created: ${checksum_path}"
