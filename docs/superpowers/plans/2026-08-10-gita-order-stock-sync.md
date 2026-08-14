# Gita Order Stock Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow the latest Gita order report to decrement each matched AgniShop SKU exactly once and push the final stock to Shopee and TikTok with visible status and Auto Sync history.

**Architecture:** Add a persistent order-SKU ledger for idempotency, then place all mutation logic in a dedicated Gita sync service which reuses `MarketplaceSyncService` for absolute-stock pushes and audit logs. The existing report service joins the ledger to present state, while the Vue page uses new endpoints for bulk and row-level actions.

**Tech Stack:** Laravel query builder, Laravel feature tests with SQLite, Vue 3 Composition API, Vite, Node test runner.

## Global Constraints

- Source is the latest successful Gita collector run and its existing `to_ship` / `shipped` rows only.
- Ledger identity is `seller_order_id + seller_sku`; a success cannot decrement stock again on later collections.
- Only `matched` rows may update stock. Unmatched and duplicate Stock Master rows are non-actionable.
- Push absolute target stock with `MarketplaceSyncService::pushTargetStock`.
- Do not update Stock Master or mark a row synced until Shopee and TikTok both succeed.
- Target failures are retryable and logged in `marketplace_sync_logs` with source `gita_order`.
- Do not expose collector tokens in any frontend copy or API response.
- Use Indonesian labels, especially `Sudah Disinkronkan` and `Belum Disinkronkan`.

---

## File Structure

- `backend/database/migrations/2026_08_10_000001_create_gita_order_stock_syncs_table.php`: durable ledger.
- `backend/app/Services/GitaOrderStockSyncService.php`: row/bulk mutation, retry, locking, pushes, logs.
- `backend/app/Services/GitaOrderScrapeService.php`: ledger join and report state.
- `backend/app/Http/Controllers/GitaOrderScrapeController.php`: action responses.
- `backend/routes/api.php`: two mutation routes.
- `backend/app/Services/MarketplaceSyncService.php`: mapping lookup and history source.
- `backend/tests/Feature/GitaOrderScrapeControllerTest.php`: backend regression coverage.
- `frontend/src/pages/gitaOrderScrapeState.js`: labels, eligibility, non-secret command constants.
- `frontend/src/pages/GitaOrderScrapeReport.vue`: guide and controls.
- `frontend/src/services/index.js`: action client methods.
- `frontend/tests/gitaOrderScrapeState.test.js`: pure UI-state tests.

### Task 1: Persist and Present Per-Order Sync State

**Files:**
- Create: `backend/database/migrations/2026_08_10_000001_create_gita_order_stock_syncs_table.php`
- Modify: `backend/app/Services/GitaOrderScrapeService.php`
- Test: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Interfaces:** Ledger fields are `seller_order_id`, `seller_sku`, `stock_master_id`, `quantity`, `status`, `message`, `old_stock`, `new_stock`, `collector_item_id`, and `synced_at`; unique identity is `(seller_order_id, seller_sku)`. The report returns `sync_status`, `sync_message`, `old_stock`, `new_stock`, and `synced_at`.

- [ ] **Step 1: Write failing report-state tests**

Record the existing successful fixture, then expect pending state before any ledger record exists:

```php
$this->getJson('/api/gita-order-scrapes/items')
    ->assertOk()
    ->assertJsonPath('items.0.sync_status', 'pending')
    ->assertJsonPath('items.0.sync_message', 'Belum Disinkronkan');
```

Insert a ledger row for the fixture order/SKU and expect `synced`, old/new stock, and timestamp. Add an unmatched-row assertion for `blocked`.

- [ ] **Step 2: Run test to verify RED**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: FAIL because sync fields and the ledger table do not exist.

- [ ] **Step 3: Add migration and latest-row join**

Create the ledger table:

```php
$table->string('seller_order_id', 100);
$table->string('seller_sku', 150);
$table->unsignedBigInteger('stock_master_id')->nullable()->index();
$table->foreignId('collector_item_id')->nullable()->constrained('gita_order_scrape_items');
$table->unsignedInteger('quantity');
$table->string('status', 32)->index();
$table->text('message')->nullable();
$table->integer('old_stock')->nullable();
$table->integer('new_stock')->nullable();
$table->timestamp('synced_at')->nullable();
$table->unique(['seller_order_id', 'seller_sku']);
```

Left-join it in `GitaOrderScrapeService::items()` on exact order id and seller SKU. In `serializeItem()`, derive `pending` for matched/no-ledger rows, `blocked` for unmatched/duplicate rows, otherwise return stored status.

- [ ] **Step 4: Run test to verify GREEN**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: PASS with existing collector authorization and failed-latest-run regressions.

- [ ] **Step 5: Commit**

Stage the migration, report service, and feature test. Commit with `feat: track Gita order stock sync state`.

### Task 2: Implement One Gita Order Stock Sync Safely

**Files:**
- Create: `backend/app/Services/GitaOrderStockSyncService.php`
- Modify: `backend/app/Services/MarketplaceSyncService.php`
- Modify: `backend/app/Http/Controllers/GitaOrderScrapeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Interfaces:**
- `GitaOrderStockSyncService::syncItem(int $itemId): array`
- `MarketplaceSyncService::findSkuMappingByStockMasterId(int $stockMasterId): ?object`
- `POST /api/gita-order-scrapes/items/{item}/sync`

- [ ] **Step 1: Write failing single-sync tests**

Bind a Mockery `MarketplaceSyncService` with a fully mapped Stock Master at quantity `7`, collector quantity `1`, and successful pushes. Assert the endpoint returns `synced`, `old_stock=7`, `new_stock=6`, updates Stock Master to `6`, and creates a `synced` ledger row.

Add separate tests for unmatched input (`422`, no push), insufficient stock (`blocked`, unchanged master), and an item from an older run (`422`).

- [ ] **Step 2: Run test to verify RED**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: FAIL because the route and service are absent.

- [ ] **Step 3: Implement mapping lookup, service, controller, and route**

Add a public Stock Master mapping query in `MarketplaceSyncService`, using the existing manual mapping joins. In `syncItem()`, verify latest/matched input, lock the ledger and Stock Master, calculate `new_stock = old_stock - quantity`, and set `processing`.

Call `pushTargetStock($mapping, 'shopee', $newStock, true)` and the TikTok equivalent. Only when both return `success` or `dry_run`, call `updateLocalStock` for both, mark ledger `synced`, and write two Gita audit logs. Otherwise keep Stock Master unchanged, mark `failed`, write both target outcomes, and return a retryable result.

Add the controller action and route; return safe validation responses only.

- [ ] **Step 4: Run test to verify GREEN**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: PASS for successful decrement, unsafe rows, and latest-run ownership.

- [ ] **Step 5: Commit**

Stage the service, mapping service, controller, route, and test. Commit with `feat: sync one Gita order SKU`.

### Task 3: Add Idempotent Bulk Sync and Auto Sync History

**Files:**
- Modify: `backend/app/Services/GitaOrderStockSyncService.php`
- Modify: `backend/app/Services/MarketplaceSyncService.php`
- Modify: `backend/app/Http/Controllers/GitaOrderScrapeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Interfaces:**
- `GitaOrderStockSyncService::syncLatest(): array` returns summary counts and item results.
- `POST /api/gita-order-scrapes/sync`
- Auto Sync history source lists include literal `gita_order`.

- [ ] **Step 1: Write failing bulk/idempotency/history tests**

Create two matched latest items, sync them in bulk, then call bulk again. Assert each Stock Master decrements once and the second call makes no new target pushes.

For a partial target error, assert local stock is preserved, ledger is `failed`, and two `marketplace_sync_logs` rows have source `gita_order`. Retry with both targets successful and assert one decrement from the original value. Add a later collector quantity-change test that serializes as blocked and refuses mutation.

- [ ] **Step 2: Run test to verify RED**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: FAIL because bulk action, quantity guard, and Gita history filtering are absent.

- [ ] **Step 3: Implement bulk loop and history source**

Select latest-run matched rows ordered by id and call `syncItem()` independently so one failure cannot block later SKUs. Treat a same-quantity `synced` ledger record as a no-op; block a later changed quantity without guessing an adjustment.

Add `gita_order` to the `MarketplaceSyncService` order-history, export, detail, dashboard summary, and filter source lists that currently enumerate `shopee_order`, `shopee_stock_refresh`, and `tiktok_order`. Preserve existing Shopee/TikTok counts unless adding a displayed Gita count.

- [ ] **Step 4: Run test to verify GREEN**

Run: `backend/vendor/bin/phpunit tests/Feature/GitaOrderScrapeControllerTest.php`

Expected: PASS for bulk execution, idempotency, partial failure/retry, logs, and quantity guard.

- [ ] **Step 5: Commit**

Stage the Gita service, MarketplaceSyncService, controller, routes, and tests. Commit with `feat: bulk sync Gita orders with audit history`.

### Task 4: Add Gita Orders Controls and Collector Guide

**Files:**
- Modify: `frontend/src/pages/gitaOrderScrapeState.js`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/pages/GitaOrderScrapeReport.vue`
- Create: `frontend/tests/gitaOrderScrapeState.test.js`
- Modify: deployed `backend/public/index.html` and only referenced assets after build.

**Interfaces:**
- `gitaOrderSyncStatusLabel(status)`, `gitaOrderSyncActionLabel(item)`, `canSyncGitaOrder(item)`.
- `omnichannelService.syncGitaOrderItems()` and `syncGitaOrderItem(itemId)`.

- [ ] **Step 1: Write failing frontend state tests**

Add Node assertions:

```js
assert.equal(gitaOrderSyncStatusLabel('pending'), 'Belum Disinkronkan')
assert.equal(gitaOrderSyncStatusLabel('synced'), 'Sudah Disinkronkan')
assert.equal(canSyncGitaOrder({ match_status: 'matched', sync_status: 'failed' }), true)
assert.equal(canSyncGitaOrder({ match_status: 'unmatched', sync_status: 'blocked' }), false)
```

Assert the daily command mentions the API base URL, profile directory, visible-browser flag, and `npm run gita-order-scrape`, but has no token name/value.

- [ ] **Step 2: Run test to verify RED**

Run: `npm --prefix frontend test -- --run`

Expected: FAIL because helpers and command constants do not exist.

- [ ] **Step 3: Implement client, state helpers, and page UI**

Add `POST` client calls for bulk and item sync. Place `Sinkronkan Semua` beside `Muat ulang`; disable it during a run or when no eligible row exists, and show aggregate results.

Add a compact `Panduan Collector` above the run overview: first-time `npm run gita-order-calibrate`, then a daily PowerShell block that sets only API base URL, profile dir, and `GITA_ORDER_SCRAPER_HEADLESS='false'` before `npm run gita-order-scrape`. Use copy-icon buttons for commands only.

Append `Sinkronisasi` and `Aksi` columns. Show pills for pending, processing, synced, failed, and blocked; show `Sinkronkan`/`Coba Lagi` only when eligible. Reload the report after every action and keep the responsive table overflow.

- [ ] **Step 4: Run test and production build**

Run: `npm --prefix frontend test -- --run`

Expected: PASS with the new Gita state test.

Run: `npm --prefix frontend run build`

Expected: exit code 0 and referenced hashed output in `frontend/dist`.

- [ ] **Step 5: Publish and commit**

Copy the rebuilt `index.html` and referenced Vite assets to `backend/public`; do not stage pre-existing obsolete assets. Commit with `feat: add Gita order stock sync controls`.

### Task 5: Verify End-to-End Behaviour and Prepare Review

**Files:**
- Verify: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`
- Verify: `backend/app/Services/GitaOrderStockSyncService.php`
- Verify: `frontend/src/pages/GitaOrderScrapeReport.vue`
- Verify: `backend/public/index.html`

- [ ] **Step 1: Run all automated suites**

Run:

```powershell
npm run test:gita-order-scraper
backend/vendor/bin/phpunit
npm --prefix frontend test -- --run
npm --prefix frontend run build
```

Expected: all commands exit 0. Do not claim success from an earlier run.

- [ ] **Step 2: Publish final build and verify deployed assets**

Publish the final Vite index and referenced assets. Request `http://agnishopbjm-laravel.test/marketplace/gita-orders`, confirm the compiled script and stylesheet return `200`, then request the report API without triggering stock mutation.

- [ ] **Step 3: Verify browser layout safely**

Check the Gita page at desktop and mobile widths: guide visible, controls fit, bulk action state matches the report, table actions do not overlap. Check Auto Sync history against fixture-safe/local test data only; do not invoke a live stock push merely for verification.

- [ ] **Step 4: Review final diff and request review**

Run `git diff --check` and `git status --short`; keep pre-existing docs, logs, and obsolete generated assets unstaged. After fresh verification succeeds, use the project review workflow and report findings before summary.
