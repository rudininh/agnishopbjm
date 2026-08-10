# Gita Order Stock Sync Design

## Goal

Turn the latest successful Gita Seller Centre order collection into an explicit, auditable stock-decrement action for the mapped AgniShop Shopee and TikTok variants. A seller order and seller SKU must never decrement stock more than once.

## Scope

- The source remains the latest successful Gita collector run and only contains `to_ship` and `shipped` order lines.
- Eligible rows must have `match_status=matched` and a single Stock Master mapping.
- A bulk action syncs every eligible, unsynchronised row in the latest run.
- A row action syncs only that seller order and seller SKU.
- The final calculated stock is sent as an absolute quantity to AgniShop Shopee and TikTok.
- Every target result is recorded in `marketplace_sync_logs` and appears in the Auto Sync history.
- The Gita Orders page shows a collector guide, per-row status, a one-click bulk action, and a one-click row action.

## Non-Goals

- Do not mutate Gita Seller Centre stock, orders, or listings.
- Do not process completed, cancelled, or historical order tabs.
- Do not add or expose a collector ingestion token in the frontend.
- Do not replace the existing marketplace order-sync, safety-check, or manual import flows.
- Do not automatically correct a previously synchronised order whose quantity changes in a later collection; mark it for review instead.

## Data Model

Create a `gita_order_stock_syncs` ledger. It is keyed by the immutable business identity:

`seller_order_id + seller_sku`

The ledger stores the current Stock Master id, quantity captured at processing time, state, requested timestamps, completion timestamp, failure message, and the related latest collector item id. The uniqueness constraint makes the operation idempotent across collector runs.

States:

- `pending`: eligible but has not yet been processed.
- `processing`: a request has reserved the ledger row.
- `synced`: both marketplace pushes completed and the local master stock was committed.
- `failed`: a marketplace request failed; retry is allowed.
- `blocked`: unsafe input, including an unmatched SKU, duplicate Stock Master SKU, missing live mapping, insufficient local stock, or a changed captured quantity after a previous successful sync.

The report endpoint decorates only latest-run items with this derived sync state. Rows with no ledger record appear as `pending` when they are eligible, or `blocked` with a specific reason when they are not.

## Sync Algorithm

1. Read and validate the latest successful collector item. It must be a matched item from the latest run.
2. Atomically create or lock its `seller_order_id + seller_sku` ledger row. A `synced` row is returned as an idempotent no-op.
3. Lock the related Stock Master row and calculate `new_stock = old_stock - captured_quantity`.
4. Stop with `blocked` when the quantity is non-positive, the Stock Master mapping is no longer complete, the source quantity changed after a previous successful sync, or the result would be below zero.
5. Set the ledger state to `processing`, then send the absolute `new_stock` to Shopee and TikTok through the existing marketplace push service.
6. If both pushes succeed, write the local Stock Master value, set the ledger to `synced`, and record two successful `marketplace_sync_logs` rows with `source_marketplace=gita_order`.
7. If either push fails, keep the local Stock Master unchanged, set the ledger to `failed`, and record each marketplace result. A retry repeats absolute-value pushes from the unchanged local stock, so it cannot double-decrement stock.

Bulk sync uses the same one-item service in a deterministic order. It returns a per-item result and leaves already-synced rows unchanged.

## API

Add to the existing Gita order controller:

- `POST /api/gita-order-scrapes/sync`: synchronise all eligible latest-run rows.
- `POST /api/gita-order-scrapes/items/{item}/sync`: synchronise one latest-run row.

Both endpoints validate that the row belongs to the latest successful run. Their responses include status, old and new stock, Shopee result, TikTok result, and a safe message. They never return tokens, raw remote responses containing credentials, or seller-page contents.

The existing report item response gains `sync_status`, `sync_message`, `synced_at`, and the prior/new stock when known.

## Auto Sync History

The existing `marketplace_sync_logs` table remains the audit source. A Gita action writes one row for Shopee and one for TikTok with:

- source `gita_order`
- seller SKU
- old and new Stock Master quantity
- per-target success or error state
- a message containing the Gita order reference without customer details

The Auto Sync order-history query recognises `gita_order`, so these entries are visible alongside regular Shopee and TikTok stock propagation records.

## Gita Orders UI

The page header gains a primary `Sinkronkan Semua` button. It is enabled only when the latest run has eligible pending or failed rows and remains a single action; it displays progress while the request runs.

The table gains a `Sinkronisasi` status column and an `Aksi` column. Eligible rows expose `Sinkronkan` or `Coba Lagi`; synced rows show `Sudah Disinkronkan`; unsafe rows show a non-actionable reason. The page refreshes the report after an action to show the persisted state.

At the top of the page, show a compact operational guide with two copyable PowerShell command blocks:

1. First-time browser login or selector calibration: `npm run gita-order-calibrate` from the project root.
2. Daily collection: set only the API base URL, persistent profile directory, and visible-browser flag, then run `npm run gita-order-scrape`.

The guide explicitly omits the ingestion token because the worker reads it from the ignored local `backend/.env` file.

## Error Handling

- Missing latest successful run: no action is enabled.
- Unmatched or duplicate SKU: never submit a marketplace update.
- Insufficient stock: block the row and preserve all marketplace quantities.
- One marketplace fails: preserve Stock Master, retain retryable failure state, and log both outcomes.
- Duplicate concurrent click: the ledger lock returns an in-progress or already-synced result rather than making another deduction.
- Changed quantity for a previously synced order/SKU: block and require manual review rather than guessing an adjustment.

## Testing

- Feature tests cover bulk and single sync, idempotency, latest-run ownership, unmatched/duplicate rejection, insufficient stock, partial target failure, retry, Auto Sync log records, and quantity-change blocking.
- Unit tests cover deterministic eligibility and sync-state derivation.
- Frontend tests cover state labels, action eligibility, and the two API calls.
- Build the Vite frontend and publish only its referenced output assets to `backend/public` before local HTTP and browser verification.
