# TikTok Warehouse Stock Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow Shopee-to-TikTok stock pushes to use the warehouse returned by TikTok product details when the optional default warehouse configuration is absent.

**Architecture:** Extend the cached TikTok SKU record with its inventory warehouse ID during product refresh. The stock sync service continues to prefer `TIKTOK_DEFAULT_WAREHOUSE_ID`, then uses the cached SKU warehouse ID as a safe fallback for the matching active TikTok SKU.

**Tech Stack:** Laravel 11, PostgreSQL/SQLite migrations, PHPUnit, Laravel HTTP fakes.

## Global Constraints

- Preserve explicit `TIKTOK_DEFAULT_WAREHOUSE_ID` as the highest-priority source.
- Use the warehouse ID only from the resolved active TikTok SKU, never from another SKU.
- Do not expose tokens, app secrets, shop ciphers, or warehouse credentials in UI or logs.
- Verify the regression test fails before production code is changed.

---

### Task 1: Persist TikTok SKU warehouse IDs

**Files:**
- Create: `backend/database/migrations/2026_08_21_000001_add_warehouse_id_to_tiktok_products_table.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/app/Services/StbMappingSyncService.php`

**Interfaces:**
- Consumes: TikTok product detail SKU `inventory[0].warehouse_id` returned during cache refresh.
- Produces: `tiktok_products.warehouse_id` for each active TikTok SKU.

- [ ] Add a guarded migration for `tiktok_products.warehouse_id`.
- [ ] Add the column to dynamic STB mapping schema setup.
- [ ] Store the SKU inventory warehouse ID with the refreshed TikTok product cache row.

### Task 2: Use the cached warehouse for stock pushes

**Files:**
- Modify: `backend/app/Services/MarketplaceSyncService.php`
- Test: `backend/tests/Unit/Services/MarketplaceSyncServiceTest.php`

**Interfaces:**
- Consumes: resolved active TikTok SKU and optional `tiktok.default_warehouse_id`.
- Produces: the existing TikTok inventory update request body with a non-empty `warehouse_id`.

- [ ] Write a failing PHPUnit test for a push with empty config and a cached SKU warehouse.
- [ ] Run the focused test and confirm it fails before the fallback exists.
- [ ] Resolve the active SKU before validating the warehouse, then use its cached warehouse only when config is empty.
- [ ] Run the focused test and confirm the TikTok request uses the cached warehouse.

### Task 3: Verify live read path and regression suite

**Files:**
- Verify: `backend/app/Services/MarketplaceSyncService.php`
- Verify: `backend/app/Http/Controllers/OmnichannelController.php`

- [ ] Run the focused service test.
- [ ] Run the related controller tests and migrations.
- [ ] Refresh TikTok product cache through the local API without performing a stock write.
- [ ] Confirm the anomaly and error-log endpoints no longer report missing warehouse configuration for refreshed SKUs.
