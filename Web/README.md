# CasaMonarca Web

Frontend project for the CasaMonarca access control system.

This project lives inside the CasaMonarca monorepo under `Web/`.

This project uses `React + Vite + TypeScript` and is deployed as a static frontend.

## Scope

- frontend stack: `React + TypeScript`
- build tool: `Vite`
- output: static files
- production target: HostGator shared hosting
- backend target: Laravel API served separately

## Local Prerequisites

Each frontend developer should install:

- `Node.js 24`
- `npm 11`
- `Git`

## First-Time Project Setup

Install dependencies:

```bash
cd Web
npm install
cp .env.example .env
```

Start the development server:

```bash
npm run dev
```

Default local URL:

```text
http://localhost:5173
```

## Local API Wiring

Recommended local setup:

1. Run the Laravel API on `http://127.0.0.1:8000`
2. Run the React frontend on `http://localhost:5173`
3. Let Vite proxy `/api/*` requests to the Laravel server

Example API start command from the monorepo root:

```bash
cd API
php artisan serve --host=127.0.0.1 --port=8000
```

Default frontend env file:

```env
VITE_APP_NAME="CasaMonarca Web"
VITE_API_BASE_URL=/api
API_PROXY_TARGET=http://127.0.0.1:8000
```

## Staging Deploy

- no `Node.js` runtime dependency on the server
- build the frontend locally
- upload only the generated static files

Use the monorepo deployment helper from the repository root:

```bash
./scripts/deploy-staging-web.sh
```

Default target values:

- host: `204.168.173.236`
- ssh user: `casamonarca`
- remote static path: `/home/casamonarca/public_html`

Override them with environment variables when needed:

```bash
REMOTE_HOST=203.0.113.10 \
REMOTE_USER=casamonarca \
SSH_KEY=~/.ssh/casamonarca_vps \
./scripts/deploy-staging-web.sh
```

The script:

- runs `npm ci` only if `node_modules/` is missing
- builds `dist/` locally
- uploads a tarball with `scp`
- promotes the build on the VPS as `casamonarca`
- normalizes remote static file modes by default
- preserves `.htaccess` and `.well-known` in the target directory

Disable permission normalization only if you explicitly need to:

```bash
RESET_PERMISSIONS=0 ./scripts/deploy-staging-web.sh
```

The script does not manage ownership or SELinux labels.
