# CasaMonarca API

Backend repository for the CasaMonarca access control system.

Current repository state: Laravel 13 skeleton generated and ready for local configuration.

## Scope

- backend framework: `Laravel 13`
- database: `MySQL` 
- production target: HostGator shared hosting
- local and VPS environments should stay close to that constraint: [HostGator software matrix](https://soporte.hostgator.mx/hc/es-419/articles/28443364586259--Cu%C3%A1l-es-la-versi%C3%B3n-del-software-principal-utilizado-en-HostGator)

## Local Prerequisites

Each backend developer should install:

- `PHP 8.3`
- `Composer 2`
- `MySQL 8.0`
- `Git`

Recommended PHP extensions:

- `bcmath`
- `ctype`
- `curl`
- `fileinfo`
- `json`
- `mbstring`
- `openssl`
- `pdo`
- `pdo_mysql`
- `tokenizer`
- `xml`
- `xmlwriter`
- `zip`

## First-Time Project Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Local MySQL Setup

Start your local MySQL service before logging in.

Examples:

```bash
# AlmaLinux / other systemd-based Linux
sudo systemctl start mysqld

# macOS with Homebrew
brew services start mysql
```

On Windows, start the MySQL service from Services, XAMPP, Laragon, or the tool used to install MySQL.


Log into MySQL with an administrative account:

```bash
mysql -uroot -p
```

Create the local database and a dedicated local user:

```sql
CREATE DATABASE IF NOT EXISTS casamonarca_api
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'casamonarca'@'localhost'
  IDENTIFIED BY 'Use-A-Strong-Local-Password-123!';

GRANT ALL PRIVILEGES ON casamonarca_api.* TO 'casamonarca'@'localhost';
FLUSH PRIVILEGES;
```

Use a strong password. Some MySQL installations enforce a password policy and will reject weak values.

After that, update `.env` with the local MySQL credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=casamonarca_api
DB_USERNAME=casamonarca
DB_PASSWORD=Use-A-Strong-Local-Password-123!
```

Run the database migrations:

```bash
php artisan migrate
```

If seeders are added later:

```bash
php artisan migrate --seed
```

## Running The API Locally

After the local `.env` is configured:

```bash
php artisan serve
```

Default local URL:

```text
http://127.0.0.1:8000
```

## Local Workflow

Typical backend workflow:

1. Pull latest changes.
2. Run `composer install` if dependencies changed.
3. Review `.env` values.
4. Run `php artisan migrate`.
5. Start the app with `php artisan serve`.

If caches need to be cleared during development:

```bash
php artisan optimize:clear
```

## Staging Pull

Use the deployment helper from the repo root to update the API code on the staging VPS:

```bash
./scripts/deploy-staging.sh
```

Default target values:

- host: `204.168.173.236`
- ssh user: `casamonarca`
- remote repo path: `/home/casamonarca/apps/api/current`
- branch: `main`

Override them with environment variables when needed:

```bash
REMOTE_BRANCH=dev \
SSH_KEY=~/.ssh/casamonarca_vps \
./scripts/deploy-staging.sh
```

This script only performs the remote `git fetch` / `git pull`. The Laravel-specific steps remain manual for now:

- `composer install --no-dev --optimize-autoloader`
- `php artisan optimize:clear`
- `php artisan migrate --force`
