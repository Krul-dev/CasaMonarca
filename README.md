# CasaMonarca Web

Frontend repository for the CasaMonarca access control system.

This project uses `React + Vite` for development and will be deployed as a static frontend. Production must not depend on a Node.js runtime on HostGator shared hosting.

## Scope

- frontend stack: `React`
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
```

Start the development server:

```bash
npm run dev
```

Default local URL:

```text
http://localhost:5173
```

## Production Build

Create the static build:

```bash
npm run build
```

Preview the build locally:

```bash
npm run preview
```

The generated output will be written to:

```text
dist/
```

## Deployment Direction

This repository should be deployed as prebuilt static assets.

- build locally, in CI, or on the VPS
- upload the contents of `dist/` to the shared hosting web root
- do not rely on a Node.js runtime in production

## Immediate Next Steps

1. Define the frontend app structure and routing.
2. Configure the API base URL strategy for local, staging, and production.
3. Build the first login screen.
4. Connect the frontend to the Laravel API health endpoint.
