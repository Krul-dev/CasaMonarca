# CasaMonarca Web

Frontend repository for the CasaMonarca access control system.

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

Example API start command in the API repository:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Default frontend env file:

```env
VITE_APP_NAME="CasaMonarca Web"
VITE_API_BASE_URL=/api
API_PROXY_TARGET=http://127.0.0.1:8000
```

How this works:

- `VITE_API_BASE_URL` is the URL used by the browser app
- `API_PROXY_TARGET` is used only by the Vite dev server
- during local development, browser requests go to `/api/...`
- Vite forwards those requests to the Laravel API on port `8000`

This avoids local CORS issues while keeping production flexible.

## Production Build

Create the static build:

```bash
npm run build
```

Preview the build locally:

```bash
npm run preview
```

Run the TypeScript checks:

```bash
npm run typecheck
```

The generated output will be written to:

```text
dist/
```

