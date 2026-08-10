# Gita Order Detail SKU Design

## Goal

Collect exact internal SKUs from Gita Collection BJM Shopee Seller Centre order
detail pages, limited to the `Perlu Dikirim` and `Dikirim` tabs, without
retaining buyer data or changing marketplace records.

## Scope

- Read only `l1-tab-toship` and `l1-tab-shipping`.
- For every visible order card and every discovered list page, find the Seller
  Centre order-detail link already present in the card.
- Read `Kode Variasi: INT-...` from the authenticated order detail page.
- Record every product line from a multi-item order as a separate report row.
- Keep Laravel ingestion bearer-token protected and always source the worker
  token from the ignored local `backend/.env` file.

## Data Flow

1. The listing page supplies the tab status, order ID, title, variation label,
   quantity, and detail URL for each visible product line.
2. A separate browser page visits the corresponding detail URL inside the same
   persistent authenticated context.
3. The detail parser extracts ordered `Kode Variasi` `INT-...` tokens only.
4. The worker pairs list lines and detail SKU tokens by their rendered product
   order. Counts must match exactly; a mismatch fails the entire run with no
   item payload.
5. The existing backend reconciles the collected SKU to `stock_master` and
   records the read-only report.

## Validation And Privacy

- A card without exactly one usable detail URL fails the run rather than
  guessing a destination.
- A detail page with a missing, duplicate, or count-mismatched variation code
  fails the run rather than generating partial rows.
- Login, MFA, or verification on either page yields `needs_login` with no item
  rows.
- The parser may inspect page text in memory to locate `Kode Variasi`, but it
  must not log or persist buyer names, addresses, messages, payment data,
  cookies, credentials, raw HTML, or screenshots.

## Token Behavior

The normal local worker always reads the ingestion token from `backend/.env`.
It does not accept `GITA_ORDER_SCRAPER_INGEST_TOKEN` from PowerShell, so a
stale session environment value cannot produce a token mismatch. Laravel API
authorization remains unchanged.
