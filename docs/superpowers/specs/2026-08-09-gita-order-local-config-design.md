# Gita Order Local Configuration Design

## Goal

Allow the local Gita order collector to run with `npm run gita-order-scrape`
without manually placing an ingestion token in PowerShell, while retaining the
protected Laravel ingestion endpoint.

## Decision

The worker loads local defaults from the repository and reads the ingestion
token only from the ignored `backend/.env` file when an explicit worker
environment value is absent. An explicit environment value continues to take
precedence for supervised deployment or diagnostic use.

## Configuration Resolution

The worker resolves configuration in this order:

1. Explicit `GITA_ORDER_SCRAPER_*` environment values.
2. Local repository defaults for the API base URL, Seller Centre order URL,
   persistent profile directory, visible browser mode, and timeout.
3. The ignored `backend/.env` value for `GITA_ORDER_SCRAPER_INGEST_TOKEN`.

If neither the environment nor `backend/.env` provides a nonblank ingestion
token, the worker fails before launching a browser and prints no secret.

## Security Boundaries

- `POST /api/gita-order-scrapes/runs` remains bearer-token protected.
- The worker never logs the token, `.env` contents, cookie data, raw HTML, or
  HTTP response bodies.
- The worker reads only the project-local `backend/.env`; it does not accept a
  caller-supplied secret-file path.
- The fallback does not apply to calibration because calibration does not send
  order data.

## Operational Result

After the token is configured once in the ignored `backend/.env`, normal local
operation is:

```powershell
Set-Location C:\laragon\www\agnishopbjm-laravel
npm run gita-order-scrape
```

The browser profile remains local and human-authenticated. The collector stays
read-only with respect to Gita, Stock Master, Shopee AgniShop BJM, TikTok
AgniShop BJM, mappings, caches, and marketplace sync logs.
