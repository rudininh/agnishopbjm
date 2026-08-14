# Gita Collection Stock Read-Only Scraper Design

## Goal

Collect a read-only Gita Collection BJM stock snapshot through a locally authenticated Seller Centre browser session. Do not change Gita, Shopee AgniShop BJM, TikTok AgniShop BJM, or `stock_master`.

## Scope

- Separate local browser worker.
- Manual login, CAPTCHA, and MFA handling.
- Snapshot history and exact-SKU comparison against `stock_master.internal_sku`.
- Read-only reporting only.

## Existing-System Fit

AgniShop already keeps the stock master and marketplace SKU mapping data in the Laravel backend. Its established marketplace auto-sync paths can mutate Shopee and TikTok quantities, so the Gita collector must remain isolated from those paths. It will write only its own run and item history tables and must not invoke `MarketplaceSyncService`, stock-consistency jobs, product-cache refreshes, or stock-master update methods.

The existing frontend may read the report through dedicated read-only API endpoints, but it will not start the browser worker. The worker is launched directly on the local machine where the Gita login session exists.

## Architecture

### Local Browser Worker

A small Node.js/Playwright worker runs outside the Laravel request process. It uses a persistent browser profile directory supplied through local environment/configuration and excluded from Git. The operator opens or re-authenticates the Gita Seller Centre session when Shopee requires it.

The worker navigates only to the Seller Centre pages required to list products and variants, extracts these normalized fields, and sends an all-or-nothing snapshot to the backend:

```text
sku
stock
gita_product_id (when visible)
gita_variant_id (when visible)
captured_at
```

`sku` is trimmed and compared as an exact, case-sensitive identifier against `stock_master.internal_sku`, matching the established marketplace lookup behavior. The implementation will not manufacture an SKU from a product name or variant name.

The worker has two intentional authentication outcomes:

- `needs_login`: the expected inventory page is unavailable because a login, CAPTCHA, MFA, or access prompt is visible.
- `failed`: a non-authentication navigation, selector, parsing, or transport failure prevents a complete valid capture.

For both outcomes, the worker sends no item snapshot. It never types credentials, solves a CAPTCHA, submits an MFA code, or modifies a seller-centre control.

### Backend Ingestion and Persistence

Laravel exposes a dedicated ingestion endpoint protected by a bearer token held only in the worker's local environment and the backend environment. The endpoint accepts a complete validated capture payload; it rejects anonymous, malformed, empty, duplicate-SKU, or partial-success payloads.

The design adds two tables:

| Table | Purpose |
| --- | --- |
| `gita_stock_scrape_runs` | One record per attempted collection: status, start/finish timestamps, summary counts, and a sanitized diagnostic message. |
| `gita_stock_scrape_items` | Immutable rows from a successful run: normalized Gita SKU, captured stock, visible external IDs, match result, linked `stock_master` ID when exactly matched, and prior captured stock for reporting. |

Only a completed run inserts item rows. Failed runs record the failure metadata without modifying a prior successful run. The item insert and run completion occur in one database transaction.

### Exact-SKU Matching

The backend resolves each received SKU to `stock_master.internal_sku` using a deterministic normalized comparison. Each item is classified as one of:

| Status | Meaning |
| --- | --- |
| `matched` | Exactly one stock-master SKU matches the Gita SKU. |
| `unmatched` | No stock-master SKU matches. |
| `duplicate_source_sku` | The worker payload contains a Gita SKU more than once. The complete payload is rejected. |
| `duplicate_master_sku` | More than one stock-master row matches. The item is recorded as an anomaly and is not linked. |
| `invalid_sku` | The scraped SKU is blank or fails the defined input validation. The complete payload is rejected. |

The report calculates `stock_changed` by comparing a matched SKU's newest successful Gita capture with its previous successful Gita capture. This is informational only; it never changes `stock_master` or sends a marketplace API call.

### Read-Only Report

Dedicated GET endpoints provide the latest run and paginated item history. The report shows captured time, total items, matched/unmatched/duplicate counts, and changed-stock rows. It can be displayed in AgniShop as a history/reporting page, but no button is allowed to run the worker or submit stock changes in this phase.

## Failure Handling and Auditability

- A worker must fetch every expected page successfully before it posts a snapshot. A page failure cannot be turned into a partial successful run.
- Backend payload validation checks the capture schema, non-negative integer quantities, SKU uniqueness, and the ingestion credential before creating data.
- Every attempt has a terminal run status: `success`, `needs_login`, or `failed`.
- Sanitized messages may identify the stage that failed, but cannot include credentials, cookies, tokens, DOM HTML, or full response bodies.
- Existing successful snapshots remain readable after a later failure.
- The backend does not make outbound calls to Gita as part of ingestion or reporting.

## Security Boundaries

- The Gita browser profile, cookies, and any worker bearer token stay outside the repository and outside application logs.
- The worker token is provided by environment variables, never hard-coded into source or browser storage.
- The backend token comparison uses a constant-time check.
- The endpoint rate and payload size are bounded to protect the backend from accidental duplicate submissions.
- The worker is explicitly read-only: it must not invoke actions that save, publish, edit, update, delete, or bulk-change a Seller Centre record.

## Testing Strategy

### Worker Tests

- Parser tests use sanitized local HTML fixtures for a single variant, multiple pages, empty SKU, invalid stock, and a login page.
- A test proves that a login/CAPTCHA/MFA detection returns `needs_login` and does not produce an ingest payload.
- A test proves that a missing page or extractor failure returns `failed` and does not produce an ingest payload.

### Backend Tests

- Feature tests cover missing/invalid bearer tokens, invalid capture payloads, empty payloads, duplicate source SKUs, and negative/non-integer stock.
- Feature tests cover exact matching, unmatched SKUs, duplicate stock-master SKUs, successful transactional persistence, and read-only report filters.
- Regression tests assert that a successful ingestion does not update `stock_master`, marketplace SKU mapping rows, Shopee cache rows, TikTok cache rows, or `marketplace_sync_logs`.

### Operational Verification

After automated verification, the operator sets the local worker environment values without committing them, runs `npm run gita-stock-scrape` with `GITA_SCRAPER_HEADLESS=false`, and completes Gita manual login, CAPTCHA, or MFA only in the visible browser. The expected terminal result is either `success` with a complete item count, or `needs_login`/`failed` with zero items.

The operator then signs in to AgniShop and reviews `/marketplace/gita-stock`. Before and after the supervised run, compare database rows to confirm that `stock_master`, `sku_mappings`, Shopee cache tables, TikTok cache tables, and `marketplace_sync_logs` have no changes from the collection. Confirm separately in Gita Seller Centre that no stock was edited or published.

Automated verification uses `npm run test:gita-stock-scraper`, `backend/vendor/bin/phpunit --filter=GitaStockScrape`, `backend/vendor/bin/phpunit`, `npm --prefix frontend test`, and `npm --prefix frontend run build`. No production schedule, cron entry, supervisor configuration, or frontend control starts the local browser worker.uy