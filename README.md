# CasaMonarca

CasaMonarca is now maintained as a single repository with the backend and frontend kept in separate project folders.

## Repository Layout

```text
CasaMonarca/
├── API/   # Laravel API
├── Web/   # React + Vite frontend
└── scripts/
    ├── deploy-staging-api.sh
    └── deploy-staging-web.sh
```

The original imported branches are:

- `import/team-601-3-backend`: source for `API/`
- `import/team-601-3-frontend`: source for `Web/`

The combined branch is:

- `feature/team-601-3-integrate`

## Prerequisites

Install the backend and frontend toolchains locally:

- PHP 8.3
- Composer 2
- MySQL 8.0
- Node.js 24
- npm 11
- Git

## First-Time Local Setup

Clone the monorepo and install each project from its own folder:

```bash
git clone https://github.com/Krul-dev/CasaMonarca.git
cd CasaMonarca
git checkout feature/team-601-3-integrate
```

Backend:

```bash
cd API
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```bash
cd ../Web
npm install
cp .env.example .env
npm run dev
```

Default local URLs:

- API: `http://127.0.0.1:8000`
- Web: `http://localhost:5173`

## Local API Wiring

The frontend expects API requests to go through `/api` during local development and Vite proxies them to Laravel.

Use this in `Web/.env`:

```env
VITE_APP_NAME="CasaMonarca Web"
VITE_API_BASE_URL=/api
API_PROXY_TARGET=http://127.0.0.1:8000
```

## Common Commands

Backend:

```bash
cd API
composer install
php artisan migrate
php artisan test
php artisan optimize:clear
```

Frontend:

```bash
cd Web
npm install
npm run lint
npm run build
```

## Staging Deployment

The staging server should clone this single repository once, for example:

```text
/home/casamonarca/apps/casamonarca/current
├── API/
└── Web/
```

Deploy API code from the monorepo root:

```bash
REMOTE_HOST=10.10.20.245 \
REMOTE_BRANCH=feature/team-601-3-integrate \
GITHUB_USERNAME=your-github-user \
GITHUB_TOKEN_FILE=/home/casamonarca/.config/casamonarca/github_token \
SSH_KEY=~/.ssh/casamonarca_vps \
./scripts/deploy-staging-api.sh
```

Then run Laravel release commands on the server:

```bash
ssh -i ~/.ssh/casamonarca_vps casamonarca@10.10.20.245
cd /home/casamonarca/apps/casamonarca/current/API
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Deploy the frontend from the monorepo root:

```bash
REMOTE_HOST=10.10.20.245 \
SSH_KEY=~/.ssh/casamonarca_vps \
./scripts/deploy-staging-web.sh
```

The frontend deploy builds `Web/dist` locally and promotes only static files to the configured web root.

## Subproject Documentation

More detailed project-specific docs remain in:

- `API/README.md`
- `Web/README.md`
