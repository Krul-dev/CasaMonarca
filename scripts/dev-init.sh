#!/usr/bin/env bash

# Initializes a complete local development environment:
# - Builds and starts Docker containers
# - Installs Composer dependencies
# - Runs database migrations
# - Seeds the database with the development administrator

set -euo pipefail

docker compose up -d --build

docker compose exec api composer install

docker compose exec api php artisan migrate:fresh --seed --force
