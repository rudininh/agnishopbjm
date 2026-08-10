# Gita Order Scraper Worker

This local worker reads order-item seller SKU data from the Gita Collection BJM
Shopee Seller Centre. It is reporting only: it never changes Gita orders or
stock, Stock Master, Shopee AgniShop BJM, TikTok AgniShop BJM, mappings,
caches, or marketplace sync logs.

## Local Configuration

Set these values only on the worker computer. Do not add real values to Git,
source files, browser storage exports, or logs.

Set the following value once in the local `backend/.env`. The worker reads this
same ignored local file automatically; do not put the token in PowerShell.

```text
GITA_ORDER_SCRAPER_INGEST_TOKEN=<local worker token>
```

Normal local operation uses these defaults:

```text
GITA_ORDER_SCRAPER_API_BASE_URL=http://agnishopbjm-laravel.test/api
GITA_ORDER_SCRAPER_START_URL=https://seller.shopee.co.id/portal/sale/order?type=toship&source=processed&sort_by=confirmed_date_asc
GITA_ORDER_SCRAPER_PROFILE_DIR=tools/gita-order-scraper/.profile
GITA_ORDER_SCRAPER_HEADLESS=false
GITA_ORDER_SCRAPER_TIMEOUT_SECONDS=30
```

Each non-secret value may be overridden through a local worker environment when
needed. The ingestion token always comes from `backend/.env`.

Optional overrides:

```text
GITA_ORDER_SCRAPER_API_BASE_URL=http://127.0.0.1:8000/api
GITA_ORDER_SCRAPER_START_URL=https://seller.shopee.co.id/portal/sale/order?type=toship&source=processed&sort_by=confirmed_date_asc
GITA_ORDER_SCRAPER_PROFILE_DIR=C:\local\gita-order-profile
GITA_ORDER_SCRAPER_HEADLESS=true
GITA_ORDER_SCRAPER_TIMEOUT_SECONDS=45
```

The persistent profile directory is ignored by Git and must remain local. Do
not place a password, cookie, session ID, token, CAPTCHA value, or MFA code in
this document or in any tracked file.

## Manual Run

## Required First Calibration

The worker selectors have been calibrated in a supervised, manually
authenticated Gita Seller Centre session. The calibration inspected only tabs,
pagination, seller order ID, product title, variant label, seller SKU, and
quantity; it did not retain customer details, browser session data, or raw
page traffic. Recalibrate with the same read-only process if Seller Centre
changes its order-page structure.

Calibration requires only `GITA_ORDER_SCRAPER_START_URL`,
`GITA_ORDER_SCRAPER_PROFILE_DIR`, and the optional timeout. It does not need
the API URL or ingestion token because it does not read or send order rows.
Run `npm run gita-order-calibrate` when a future Seller Centre change requires
recalibration; the visible worker browser remains open until `Ctrl+C` is pressed
in the terminal.

## Manual Run After Calibration

1. Confirm `backend/.env` has a local `GITA_ORDER_SCRAPER_INGEST_TOKEN`.
2. Run `npm run gita-order-scrape` from the repository root. The normal local
   API URL, Seller Centre URL, persistent profile, visible browser mode, and
   ingestion token are loaded automatically.
3. In the visible browser profile opened by the worker, log into the Gita
   Collection BJM Seller Centre account yourself. Complete any CAPTCHA or MFA
   yourself.
4. Leave the worker to read the `Perlu Dikirim` and `Dikirim` tabs and all
   discovered pages. For each order it opens the read-only detail page to read
   `Kode Variasi`. Do not use edit, save, publish, update, delete, bulk-change,
   or order-action controls in Seller Centre.

The worker sends exactly one terminal result:

- `success` only after both required tabs, all discovered pages, and every
  order detail page are collected.
- `needs_login` with no item rows when login, CAPTCHA, MFA, or verification is
  required.
- `failed` with no item rows when navigation, parsing, deduplication, or
  delivery cannot complete.

The worker reads only seller order ID, tab status, seller SKU from `Kode
Variasi`, product title, variant label, quantity, and capture time. It does
not collect buyer details, payment data, courier tracking, product images,
credentials, cookies, or raw Seller Centre traffic.

Every listed product line must have a corresponding exact `Kode Variasi:
INT-...` value in its order detail. Missing, conflicting, or count-mismatched
detail codes fail the run rather than sending partial rows.

## Review Results

Open `/marketplace/gita-orders` in AgniShop to review the latest terminal run
and exact seller-SKU reconciliation results. The page is read-only and does
not require an AgniShop login. A non-success run must show no order-item rows.

Before and after a supervised run, verify that `stock_master`, SKU mappings,
Shopee and TikTok cache tables, and `marketplace_sync_logs` have not changed
because of the worker. Confirm in Seller Centre that no order or stock action
was performed.
