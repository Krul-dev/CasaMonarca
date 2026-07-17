#!/usr/bin/env bash

set -euo pipefail

docker compose up -d --build

docker compose exec api composer install

docker compose exec api php artisan migrate:fresh --seed --force
