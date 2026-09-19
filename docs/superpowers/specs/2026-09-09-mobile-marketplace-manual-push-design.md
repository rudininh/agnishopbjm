# Mobile Marketplace Manual Push Design

## Goal

Extend `Kelola Produk Mobile` with an auditable workflow to select a Stock Master variant, set one authoritative stock quantity, update channel-specific prices, and manually push selected values to mapped marketplace accounts.

The supported accounts are Shopee AgniShopBJM, Shopee GitaCollectionBJM, and TikTok AgniShopBJM.

## Scope

Phase 2A covers only:

- Inspect one Stock Master variant and its three marketplace targets.
- Set one Stock Master stock quantity and optionally deliver that exact quantity to selected mapped accounts.
- Set prices independently for the two Shopee accounts and TikTok.
- Store an immutable delivery result for each selected account.
- Retry a failed account delivery without silently changing Stock Master again.

It does not add product creation, variant creation, image upload, scheduled synchronization, STB Armbian execution, or reconciliation of external stock edits. Those are later phases.

## Inventory Rule

`stock_master.stock_qty` remains the sole authoritative inventory balance. The operator enters one desired quantity, never three marketplace-specific quantities.

When a marketplace target is selected, the application first commits the Stock Master change through the existing ledger-backed adjustment flow. It then sends that committed quantity to every selected account independently. A delivery error does not roll Stock Master back: the ledger remains authoritative and the failed target is visibly retryable.

Prices are channel-specific. Changing a Shopee or TikTok price cannot alter Stock Master stock, another account's price, or an account that was not selected.

## Mobile Workflow

The selected Stock Master result in `mobile/kelola-produk` opens a variant detail view with internal SKU, product and variant names, current Stock Master quantity, recent adjustment history, and three account cards.

Each account card shows its name and channel, mapping status and remote SKU or variant identity, last delivered stock and price, delivery time, last result, an independent price input, and a delivery checkbox only when ready with exactly one active mapping.

`Simpan & Push` opens a confirmation showing the master quantity, selected accounts, and individual prices. After the committed local change, the page reports `success`, `failed`, `blocked`, or `skipped` for every target. `Coba Lagi` is available only for a failed target and reuses its committed stock quantity and requested price; it creates no new ledger entry.

## Backend Architecture

A mobile variant-detail service composes the Stock Master variant, current quantity, recent adjustment ledger entries, and exact `marketplace_listings` for the three registered accounts. It applies `MarketplaceAccountRegistry` readiness and returns only sanitized account state.

An account is selectable only when it is enabled, has `ready` credentials and token state, has exactly one active listing for the Stock Master variant, and has the remote identity required by its update gateway. Otherwise it is returned as `mapping_required`, `authorization_required`, `waiting_credentials`, `token_expired`, or `disabled` and cannot be pushed.

A dedicated coordinator accepts the Stock Master variant, desired master quantity, selected account keys, and optional per-account prices. It validates fresh account state, uses `MobileStockAdjustmentService` only if the quantity changed, creates a delivery run, invokes each selected account gateway independently, persists the result, and returns a sanitized summary.

The coordinator reuses existing account registry, credential resolution, payload builder, and gateway patterns. It never trusts a remote identifier, credential, token, account configuration, or a non-committed stock quantity supplied by the browser.

## Delivery History

Add a manual marketplace update run and item-result model rather than overloading product-publication history. A run records the authenticated operator, Stock Master variant, committed stock quantity, and request time. Each item result records the account key, mapped listing reference, requested price, final status, sanitized response or error code, and completion time.

This history makes partial success visible, supports targeted retry, and keeps remote delivery audit separate from Stock Master adjustment history. Access tokens, refresh tokens, partner keys, authorization codes, and raw credential payloads must never be stored in run results.

## API Contract

- `GET /api/mobile/stock-master/{stock_master}/marketplace-detail` returns the selected variant, recent ledger entries, and three sanitized account cards.
- `POST /api/mobile/stock-master/{stock_master}/marketplace-pushes` accepts desired `stock_qty`, adjustment reason and optional note when the quantity changes, selected account keys, and optional per-account prices. It returns the committed master balance and per-account delivery results.
- `POST /api/mobile/marketplace-pushes/{run}/items/{item}/retry` retries exactly one failed account item using its recorded request. It does not create another stock adjustment.
- `GET /api/mobile/stock-master/{stock_master}/marketplace-pushes` returns recent sanitized manual push history for that variant.

All endpoints require authenticated dashboard access. Invalid payloads, unavailable mappings, and readiness failures return HTTP 422. Missing variants or runs return HTTP 404. Retrying a non-failed item returns HTTP 409. External API failures are persisted as failed item results and returned in a successful request envelope, so results for other selected targets are preserved.

## Safeguards

- The server derives account and remote item identity only from `marketplace_listings`.
- Shopee GitaCollectionBJM stays fail-closed whenever mapping, token, shop identity, or credentials are not ready.
- Unready accounts are not selected by default.
- Viewing the page or changing a price input never invokes a marketplace API.
- Automatic jobs, queues, scheduler tasks, and STB Armbian calls remain disabled.
- The UI distinguishes local stock commit from each remote delivery; it never claims all stores synchronized if any account fails or is blocked.

## Testing

- Feature tests cover authenticated detail reads, mapping/readiness gating, Stock Master updates, account-specific price validation, complete success, partial failure, blocked Gita delivery, and targeted retry without another ledger row.
- Service tests cover exact master-quantity propagation, per-account price isolation, result persistence, sanitized error storage, and no external call for unmapped or unready accounts.
- Frontend tests cover card status rendering, confirmation payload creation, partial results, retry behavior, and separation of master stock from channel prices.
- Focused backend and frontend suites, then the existing full backend and frontend suites, must pass before Phase 2A is complete.

## Follow-up Phases

### Phase 2B: Add Variant Wizard

Create a local product variant with name, internal SKU, Stock Master quantity, image, and optional account-specific prices. Preflight each selected account, persist local data first, then publish independently to every selected marketplace. Publication exposes partial success and retry; it cannot claim global success until all selected accounts succeed.

### Phase 2C: Reconciliation

Refresh marketplace quantities, surface discrepancies against Stock Master, and require an operator to adopt the marketplace value into Stock Master or re-push the committed master value. Automatic synchronization remains disabled until mapping gaps and unresolved discrepancies are controlled.
