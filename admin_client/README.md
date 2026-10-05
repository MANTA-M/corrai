# Corrai Admin Client

Vue 3 + TypeScript admin frontend for listing schools and monitoring Redis queues.

## Quick Start

```bash
cd admin_client
npm install
npm run dev
```

The development server runs with base path `/adm/`. The Vue app it links to is served at `/`.

On the test server, build with `VITE_BASE=/corrai_test_adm/` and `VITE_CORRAI_ROOT=/corrai_test`.

## Build

```bash
npm run build
```

Output is written to `dist/` and served at `/adm/` by nginx.
