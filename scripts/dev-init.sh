#!/bin/bash
set -e

docker compose up -d --build

docker compose exec api composer install

docker compose exec api php artisan migrate

docker compose exec api php artisan db:seed
