# SPA Route Fallback Design

## Goal

Make the local dashboard routes, including `/sinkronisasi-stok`, available after a direct browser request or refresh.

## Chosen Design

Place an Apache `.htaccess` fallback in `frontend/public`. Vite copies this file into `frontend/dist` during `npm run build`. The fallback serves `index.html` for non-existent browser routes while preserving existing files and the `/api` aliases handled by the local virtual host.

## Validation

The direct local requests to `/dashboard` and `/sinkronisasi-stok` must return HTTP 200 after a frontend build. `/api/health` must remain HTTP 200.
