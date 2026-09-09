# Mobile Stock Master Transition Design

## Goal

Make `Kelola Produk Mobile` the safe daily workflow for inventory changes before Stock Master is made the authoritative source for Shopee AgniShopBJM, Shopee GitaCollectionBJM, and TikTok AgniShopBJM.

## Scope

Phase one replaces the unsafe direct-edit behavior for stock with an audited Stock Master adjustment flow. Marketplace prices and cost prices remain on their existing workflow. Automatic marketplace pushes, scheduled execution, and STB Armbian execution remain disabled in this phase.

## Architecture

### Inventory adjustment ledger

Add a durable `stock_adjustments` ledger. Every daily change creates one immutable row containing the Stock Master variant, before and after quantities, signed delta, adjustment reason, optional note, authenticated operator, and timestamps. Reasons are constrained to `receiving`, `sale`, `return`, `damaged`, and `correction`.

The adjustment service runs in one database transaction. It locks the `stock_master` row, rejects a negative result, updates `stock_qty`, and writes the ledger entry. It must never update Shopee or TikTok cache tables directly.

### Mapping and delivery visibility

The existing `marketplace_listings` table is the only source for delivery targets. After an adjustment, the API returns one target per registered account and classifies it as:

- `mapped_pending`: active listing exists and can later be delivered.
- `mapping_required`: no active listing exists; no delivery attempt is allowed.
- `disabled`: account is disabled or unavailable.

Phase one records no external push and never reports a marketplace update as successful. It only makes the intended delivery state visible to the operator.

### Mobile workflow

The mobile page adds a Stock Master mode alongside the current marketplace product browser. Operators search by internal SKU, mapped seller SKU, product name, or variant name; choose one Stock Master variant; enter a signed increase/decrease; select a required reason; optionally add a note; and submit.

The result shows the new master quantity, a concise per-account delivery state, and the latest adjustment history. Existing marketplace-specific price/cost editing remains visible but is explicitly separated from stock adjustment so it cannot silently become the stock source of truth.

### Transition safeguards

During transition, manual changes made directly in Shopee or TikTok are not automatically imported or overwritten. A later reconciliation phase must compare refreshed marketplace quantities with `stock_master.stock_qty`, create reviewable anomalies, and require an explicit operator action to adopt or re-apply a value.

Automatic stock pushes must remain disabled until all of the following are true:

1. The daily mobile workflow is in use and has an auditable history.
2. Each active Stock Master SKU has one unambiguous mapping for every intended marketplace account.
3. Marketplace reconciliation has no unresolved anomalies.
4. The operator has explicitly enabled live push after a dry run.

## API Contract

- `GET /api/mobile/stock-master/search?search=&per_page=` returns Stock Master variants and active marketplace listing states.
- `POST /api/mobile/stock-master/adjustments` accepts `stock_master_id`, `delta`, `reason`, and optional `note`; it returns the committed ledger record, new stock balance, and delivery states.
- `GET /api/mobile/stock-master/adjustments?stock_master_id=` returns the most recent ledger entries for that variant.

All state-changing endpoints require the same authenticated admin access as the dashboard. Validation errors use HTTP 422, missing variants use HTTP 404, and insufficient stock uses HTTP 422. No external marketplace API call is made by these endpoints.

## Error Handling

- Reject zero delta, unsupported reasons, missing target variants, and balances below zero.
- Fail closed if the Stock Master row cannot be locked or the transaction fails.
- Present mapping gaps as actionable status, never as a successful sync.
- Do not expose marketplace credentials, access tokens, refresh tokens, or partner keys in API output or UI.

## Testing

- Feature tests cover search, successful additions and deductions, validation, prevention of negative balances, authenticated operator attribution, and immutable history.
- Service tests cover transaction behavior and delivery-state calculation for mapped and unmapped accounts.
- Frontend state tests cover request payload generation and response-to-status presentation.
- Build and focused backend/frontend test suites must pass before this phase is reported complete.

## Out of Scope

- Live push to Shopee or TikTok.
- Marketplace stock overwrite, scheduler activation, and STB worker installation.
- Automatic adoption of stock edits made directly in a marketplace.
