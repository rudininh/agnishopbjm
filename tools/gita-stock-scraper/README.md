# Gita Stock Scraper Worker

This worker will read Gita Collection BJM inventory in a local browser profile and send only a read-only stock snapshot to AgniShop. It does not update Gita, Stock Master, Shopee, or TikTok.

## Local Configuration

Set these environment values on the worker computer. Do not add their values to Git, source files, browser storage, or logs.

```text
GITA_SCRAPER_API_BASE_URL=http://127.0.0.1:8000/api
GITA_SCRAPER_INGEST_TOKEN=<local worker token>
GITA_SCRAPER_INVENTORY_URL=<Gita Seller Centre inventory page>
GITA_SCRAPER_PROFILE_DIR=tools/gita-stock-scraper/.profile
```

Optional values:

```text
GITA_SCRAPER_HEADLESS=false
GITA_SCRAPER_TIMEOUT_SECONDS=30
```

The persistent profile directory is ignored by Git. Start with `GITA_SCRAPER_HEADLESS=false`, complete Seller Centre login, CAPTCHA, or MFA manually in the visible browser, and keep the profile only on the local worker computer.

The CLI implementation and operational run instructions are added in later tasks. Do not place a password, cookie, or token in this document.
