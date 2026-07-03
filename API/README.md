# CasaMonarca API

Backend project for the CasaMonarca access control system.

This project lives inside the CasaMonarca monorepo under `API/`.

## Scope

- backend framework: `Laravel 13`
- database: `MySQL` 
- production target: HostGator shared hosting
- local and VPS environments should stay close to that constraint: [HostGator software matrix](https://soporte.hostgator.mx/hc/es-419/articles/28443364586259--Cu%C3%A1l-es-la-versi%C3%B3n-del-software-principal-utilizado-en-HostGator)

## Canonical Roles (Current)

- `admin`: full operational and configuration access
- `coordinator`: operational role with elevated document responsibilities
- `non_coordinator`: standard operational access (default role for new users)
- `volunteer`: limited role intended for document submission flows

## Authorization Guard Scaffold

Current role middleware alias:

- `requireRole`
- `requireSecurityEnrollment` (currently enforces role-based enrollment before protected modules: coordinator `TOTP + passkey`, non-coordinator/volunteer `TOTP`)

Current admin-only probe endpoint:

- `GET /admin/authorization-check` (requires authenticated `admin`)
- `GET /admin/users` (requires authenticated `admin`; read-only account directory)
- `POST /admin/users/{user}/role/options` and
  `POST /admin/users/{user}/role/verify` (requires authenticated `admin` plus a
  fresh admin passkey assertion; assigns `coordinator`, `non_coordinator`, or
  `volunteer`; coordinator promotion requires the target user to already have a
  registered passkey; admin accounts are locked for a later hardened flow)
- `POST /admin/users/{user}/recovery/options` and
  `POST /admin/users/{user}/recovery/verify` (requires authenticated `admin`
  plus a fresh admin passkey assertion; resets TOTP enrollment or revokes all
  target passkeys for operational accounts)

Invite endpoints (draft -> verify -> issue flow):

- `POST /admin/invites` (requires authenticated `admin` or `coordinator`)
  - creates invite draft
  - target roles currently supported for admin: `coordinator`, `non_coordinator`, `volunteer`
  - coordinator may issue `non_coordinator` and `volunteer` invites
- `POST /admin/invites/{invite}/verify-out-of-band`
  - records required identity verification gate
- `POST /admin/invites/{invite}/issue-link`
  - returns the one-time registration token and link after verification
- `GET /admin/invites`
  - loads recent invites (coordinators only see their own volunteer invites)
- `POST /admin/invites/{invite}/revoke`
  - revokes pending invites; redeemed invites cannot be revoked
- `POST /invites/redeem`
  - public registration endpoint using issued invite token + matching email
  - creates account with invited role and consumes invite (`used_at`)
- `POST /totp/enroll/options`
  - starts in-session TOTP setup for authenticated users
- `POST /totp/enroll/verify`
  - verifies setup code and enables TOTP for the current user

Security baseline:

- target roles currently supported for admin: `coordinator`, `non_coordinator`, `volunteer`
- coordinator may issue `non_coordinator` and `volunteer` invites
- registration links cannot be issued before out-of-band verification
- invite tokens are returned once, while only token hashes are stored server-side
- invite endpoints use throttling, and redemption tracks repeated failures per token/IP

Current 403 contract for role-forbidden requests:

```json
{
  "message": "Forbidden.",
  "error": {
    "code": "forbidden_role",
    "requiredRoles": ["admin"],
    "currentRole": "coordinator"
  }
}
```

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

Before running migrations, configure MySQL as described below. The copied `.env`
defaults to the documented local database name and user, but you must create
that database and set your actual local password first.

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
php artisan optimize:clear
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

## Running Tests With MySQL

This repository no longer assumes SQLite for automated tests. Feature tests should run against a dedicated MySQL test schema instead.

Create the test database:

```sql
CREATE DATABASE IF NOT EXISTS casamonarca_api_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON casamonarca_api_test.* TO 'casamonarca'@'localhost';
FLUSH PRIVILEGES;
```

Create a testing env file from the committed template:

```bash
cp .env.testing.example .env.testing
```

Then update `.env.testing` with the correct local MySQL password if needed.

If your local MySQL installation is socket-backed, prefer `DB_HOST=localhost` in `.env.testing` so the test suite uses the local socket instead of forcing TCP.

Safety rule:

- tests must point to a dedicated schema such as `casamonarca_api_test`
- the test bootstrap will refuse to run if `DB_DATABASE` does not look like a test database

Run the test suite:

```bash
php artisan test
```

## Local First Admin Bootstrap

After migrations, create a local admin account from Tinker:

```bash
php artisan tinker
```

```php
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$admin = User::updateOrCreate(
    ['email' => 'admin@example.com'],
    [
        'name' => 'Local Admin',
        'role' => UserRole::Admin->value,
        'status' => UserStatus::Active->value,
        'email_verified_at' => now(),
        'password' => Hash::make('ChangeMeLocal#2026!'),
        'two_factor_enabled' => false,
        'two_factor_secret' => null,
    ],
);

$admin->only(['id', 'name', 'email', 'role', 'status']);
```

Log in with:

```text
admin@example.com
ChangeMeLocal#2026!
```

Admin accounts are expected to complete the required local security enrollment before using protected admin modules:

- TOTP enrollment
- passkey/WebAuthn registration

## Local TOTP Bootstrap (Without UI Setup Yet)

Current authentication contract:

- `POST /login`
  - returns `200` with user payload when TOTP is not enabled
  - returns `202` with `requiresTwoFactor: true` when TOTP is enabled
- `POST /login/totp`
  - completes authentication with a valid 6-digit TOTP code

You can bootstrap a local test user from Tinker:

```bash
php artisan tinker
```

```php
$totp = app(App\Services\Auth\TotpService::class);
$secret = $totp->generateSecret();

$user = App\Models\User::where('email', 'cmadmin@muchosnumeros.online')->firstOrFail();
$user->forceFill([
    'two_factor_enabled' => true,
    'two_factor_secret' => $secret,
])->save();

$secret; // register this secret in your authenticator app
```

Optional check from Tinker:

```php
$totp->currentCode($secret); // compare with your authenticator code
```

## Dev WebAuthn Security-Key Registration (Preview)

A minimal registration-only WebAuthn flow is available for local/dev testing:

- `POST /webauthn/register/options`
- `POST /webauthn/register/verify`
- `GET /webauthn/credentials`
- `POST /webauthn/login/options`
- `POST /webauthn/login/verify`

Notes:

- this currently registers keys to the authenticated user
- passkey login is available as an alternative sign-in path
- assertion verification now validates RP ID hash, user-presence flag, signature, and sign counter before login
- attestation trust-chain validation is still pending hardening
- use localhost or a real domain for WebAuthn registration; raw IP origins (for example `127.0.0.1`) are rejected

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
