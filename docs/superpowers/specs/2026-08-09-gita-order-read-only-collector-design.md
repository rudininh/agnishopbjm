# Gita Order Read-Only Collector Design

## Purpose

Replace the phase-one Gita inventory collector with a local, human-authenticated
collector for Gita Collection BJM Seller Centre order items. The collector reads
seller SKU information from the Perlu Dikirim, Dikirim, and Selesai order tabs,
then presents exact SKU reconciliation results in AgniShop.

The feature is reporting only. It must not change stock or order state in Gita,
Stock Master, Shopee AgniShop BJM, TikTok AgniShop BJM, mappings, caches, or
marketplace sync logs.

## Authentication And Browser Boundary

The local worker owns a persistent browser profile. A human signs in to the
Gita Collection BJM Seller Centre account in that visible browser and handles
login, CAPTCHA, and MFA. The worker never receives, copies, stores, prints, or
logs a password, cookie, token, session ID, CAPTCHA response, or MFA code.

The AgniShop report page is available without AgniShop authentication on the
local application. The worker ingestion endpoint remains protected by its
dedicated bearer token.

## Collection Scope

The worker visits these Seller Centre order tabs and follows their pagination:

- Perlu Dikirim
- Dikirim
- Selesai

For each order item it records only:

- Seller order ID
- Current tab status
- Seller SKU
- Product title
- Variant label
- Quantity
- Capture timestamp

It does not record buyer names, buyer messages, delivery addresses, phone
numbers, payment details, courier tracking numbers, or product images.

## SKU Rules

The seller SKU is parsed from the order-item detail shown by Seller Centre. The
collector preserves its exact text and does not normalize case, remove
characters, or infer a match from a product ID. The expected seller SKU format
is commonly an `INT-...` code, but the real selector and text shape must be
calibrated from the authenticated page before any successful live run.

Each captured item receives one exact match status against
`stock_master.internal_sku`:

- `matched`
- `unmatched`
- `duplicate_master_sku`

The collector reports the status only. It does not create or edit SKU mappings.

## Persistence And Dedupe

Order collection uses separate run and item storage from the existing Gita
stock snapshot feature because order lines are not stock snapshots.

A run is terminal as `success`, `needs_login`, or `failed`.

- `success` is valid only after all three tabs and all discovered pages are
  read, parsed, and delivered.
- `needs_login` and `failed` contain no item rows.
- An order item is uniquely identified inside a run by seller order ID, exact
  seller SKU, and variant label. Duplicate rows are rejected rather than
  silently counted twice.

The report retains immutable historical runs. It may show row-level results and
aggregate quantities by exact seller SKU, but it never derives or writes stock
updates.

## Report UI

Rename the Marketplace menu and page from Stok Gita to Pesanan Gita. The page
shows the latest terminal run, counts by match status, status filters, and a
paginated order-item table. It includes no controls for updating stock, orders,
or marketplace listings.

## Failure Handling

Login, CAPTCHA, MFA, missing table structure, unexpected SKU text, duplicate
order items, unreadable pagination, and backend delivery failures cause a
terminal non-success run. The worker must not upload a partial collection as
successful.

## Verification

Automated tests cover parsing, tab traversal contract, dedupe, exact SKU
matching, unauthenticated report reads, protected worker ingestion, and the
absence of marketplace or stock mutations.

Live verification requires a supervised authenticated Seller Centre session.
It confirms that all three order tabs and pagination are read, that displayed
SKU values are exact, and that no Seller Centre action or AgniShop marketplace
state is modified.
