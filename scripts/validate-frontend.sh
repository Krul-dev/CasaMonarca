#!/usr/bin/env bash

set -euo pipefail

BOLD='\033[1m'
BLUE='\033[34m'
GREEN='\033[32m'
RESET='\033[0m'

section() {
  printf "\n${BOLD}${BLUE}==> %s${RESET}\n\n" "$1"
}

printf "\n${BOLD}${BLUE}========================================\n"
printf "  Validating Frontend Environment\n"
printf "========================================${RESET}\n\n"

export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
[ -s "$NVM_DIR/nvm.sh" ] && source "$NVM_DIR/nvm.sh"

cd "$(dirname "$0")/../Web"

section "Configuring Node.js environment"
nvm install
nvm use

section "Installing dependencies"
npm ci

section "Auditing production dependencies"
npm audit --omit=dev

section "Running ESLint"
npm run lint

section "Running TypeScript checks"
npm run typecheck

section "Running automated tests"
npm test

section "Building production frontend"
npm run build

printf "\n${BOLD}${GREEN}========================================\n"
printf "  Frontend Validation Successful\n"
printf "========================================${RESET}\n\n"

cat <<'EOF'
All validation steps passed:

  - Node.js environment configured
  - Dependencies installed
  - Production dependencies audited
  - Lint checks passed
  - Type checks passed
  - Automated tests passed
  - Production build completed

Production artifacts are available in Web/dist/
EOF
