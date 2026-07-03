# CasaMonarca API

Backend project for the CasaMonarca access control system.

This project lives inside the CasaMonarca monorepo under `API/`.

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
cd API
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

Typical backend workflow from the monorepo root:

1. Pull latest changes.
2. `cd API`.
3. Run `composer install` if dependencies changed.
4. Review `.env` values.
5. Run `php artisan migrate`.
6. Start the app with `php artisan serve`.

If caches need to be cleared during development:

```bash
php artisan optimize:clear
```

## Running Tests

The test suite uses MySQL, matching the project runtime. Tests must point to a dedicated database whose name starts with `test_` or ends with `_test`; this prevents `RefreshDatabase` from wiping the local development database.

Create a local test database:

```sql
CREATE DATABASE IF NOT EXISTS casamonarca_api_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON casamonarca_api_test.* TO 'casamonarca'@'localhost';
FLUSH PRIVILEGES;
```

Run tests with explicit test database settings:

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_DATABASE=casamonarca_api_test \
DB_USERNAME=casamonarca \
DB_PASSWORD=Use-A-Strong-Local-Password-123! \
php artisan test
```

## Staging Pull

Use the monorepo deployment helper from the repository root:

```bash
./scripts/deploy-staging-api.sh
```

Default target values:

- host: `204.168.173.236`
- ssh user: `casamonarca`
- remote monorepo path: `/home/casamonarca/apps/casamonarca/current`
- API subdirectory: `API`
- branch: `feature/team-601-3-integrate`

Override them with environment variables when needed:

```bash
REMOTE_BRANCH=feature/team-601-3-integrate \
SSH_KEY=~/.ssh/casamonarca_vps \
./scripts/deploy-staging-api.sh
```

If the VPS repository remote uses HTTPS instead of SSH, you can also provide GitHub credentials this way:

```bash
GITHUB_USERNAME=your-github-user \
GITHUB_TOKEN_FILE=/home/casamonarca/.config/casamonarca/github_token \
SSH_KEY=~/.ssh/casamonarca_vps \
REMOTE_BRANCH=feature/team-601-3-integrate \
./scripts/deploy-staging-api.sh
```

`GITHUB_TOKEN_FILE` is a path on the VPS, not on your local machine.

This script only performs the remote `git fetch` / `git pull` for the monorepo checkout. The Laravel-specific steps remain manual under `API/`:

```bash
cd /home/casamonarca/apps/casamonarca/current/API
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

By default it also normalizes file modes after the pull:

- directories: `755`
- files: `644`
- `artisan` and `scripts/*.sh`: executable
- `.env`: `600`

Disable that only if you explicitly need to:

```bash
RESET_PERMISSIONS=0 ./scripts/deploy-staging-api.sh
```

The script does not manage ownership or SELinux labels.
