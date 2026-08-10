# Gita Order Read-Only Collector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect exact seller SKU order lines from three Gita Seller Centre order tabs and expose a read-only reconciliation report in AgniShop.

**Architecture:** New order-specific Laravel storage and APIs remain separate from stock snapshots. A persistent human-authenticated browser reads tabs and pages before posting one terminal payload.

**Tech Stack:** Laravel 11/PHP 8.3, Vue 3, Vite, Node tests, Playwright, LinkeDOM.

## Global Constraints

- Preserve seller SKU exactly and match only to `stock_master.internal_sku`.
- Store only seller order ID, tab status, seller SKU, product title, variant label, quantity, and capture time.
- Never store buyer data, payment data, credentials, cookies, session IDs, CAPTCHA values, MFA codes, or raw Seller Centre traffic.
- Never alter Gita orders or stock, Stock Master, Shopee, TikTok, mappings, caches, or `marketplace_sync_logs`.
- `success` requires all three tabs and every discovered page. Non-success runs have no item rows.
- Public local report GET endpoints; dedicated bearer token required for worker POST.

---

## File Structure

- `backend/database/migrations/2026_08_09_000001_create_gita_order_scrape_tables.php`: immutable run and item tables.
- `backend/config/gita_order_scraper.php`, `backend/app/Services/GitaOrderScrapeService.php`, and `backend/app/Http/Controllers/GitaOrderScrapeController.php`: protected ingestion and public report reads.
- `tools/gita-order-scraper/src/{config,orders,client,cli}.js`: browser traversal, parsing, and delivery.
- `frontend/src/pages/GitaOrderScrapeReport.vue`: public read-only report.

## Task 1: Create Order Storage

**Files:**
- Create: `backend/database/migrations/2026_08_09_000001_create_gita_order_scrape_tables.php`
- Create: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Produces:** `gita_order_scrape_runs` and `gita_order_scrape_items`.

- [ ] **Step 1: Write the failing persistence test**

```php
public function test_authorized_worker_persists_a_read_only_order_line(): void
{
    $this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())
        ->assertCreated();
    $this->assertDatabaseHas('gita_order_scrape_items', [
        'seller_order_id' => '260808T15MHC24',
        'tab_status' => 'to_ship',
        'seller_sku' => 'INT-40908729245-SAGEE',
        'variant_label' => 'Sagee',
        'quantity' => 1,
    ]);
}
```

- [ ] **Step 2: Verify RED**

Run: `vendor\bin\phpunit tests\Feature\GitaOrderScrapeControllerTest.php`

Expected: FAIL with missing order route or table.

- [ ] **Step 3: Create storage without personal-data columns**

```php
Schema::create('gita_order_scrape_runs', function (Blueprint $table): void {
    $table->id();
    $table->string('status', 32);
    $table->timestamp('started_at');
    $table->timestamp('finished_at');
    $table->text('message')->nullable();
    $table->unsignedInteger('item_count')->default(0);
    $table->unsignedInteger('quantity_count')->default(0);
    $table->unsignedInteger('matched_count')->default(0);
    $table->unsignedInteger('unmatched_count')->default(0);
    $table->unsignedInteger('duplicate_master_count')->default(0);
    $table->timestamps();
});
```

```php
Schema::create('gita_order_scrape_items', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('run_id')->constrained('gita_order_scrape_runs')->cascadeOnDelete();
    $table->unsignedBigInteger('stock_master_id')->nullable()->index();
    $table->string('seller_order_id', 100)->index();
    $table->string('tab_status', 32)->index();
    $table->string('seller_sku', 150)->index();
    $table->string('product_title', 500);
    $table->string('variant_label', 300)->default('');
    $table->unsignedInteger('quantity');
    $table->string('match_status', 32);
    $table->timestamp('captured_at');
    $table->timestamps();
    $table->unique(['run_id', 'seller_order_id', 'seller_sku', 'variant_label'], 'gita_order_run_line_unique');
});
```

Task 1 storage schema complete.

- [ ] **Step 4: Verify GREEN**

Run: `php artisan migrate` then `vendor\bin\phpunit tests\Feature\GitaOrderScrapeControllerTest.php`

Expected: migration succeeds and the focused test passes.

- [ ] **Step 5: Commit**

Run: `git add backend/database/migrations/2026_08_09_000001_create_gita_order_scrape_tables.php backend/tests/Feature/GitaOrderScrapeControllerTest.php` then `git commit -m 'feat: add Gita order scrape storage'`.

## Task 2: Implement Order Ingestion And Public Reports

**Files:**
- Create: `backend/config/gita_order_scraper.php`
- Create: `backend/app/Services/GitaOrderScrapeService.php`
- Create: `backend/app/Http/Controllers/GitaOrderScrapeController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Consumes:** `{ status, started_at, finished_at, items? }`; success items contain `seller_order_id`, `tab_status`, `seller_sku`, `product_title`, `variant_label`, `quantity`, and `captured_at`.

**Produces:** `POST /api/gita-order-scrapes/runs`, `GET /api/gita-order-scrapes/latest`, and `GET /api/gita-order-scrapes/items`.

- [ ] **Step 1: Write failing endpoint tests**

```php
$this->postJson('/api/gita-order-scrapes/runs', $this->successPayload())->assertUnauthorized();
$this->withToken('worker-secret')->postJson('/api/gita-order-scrapes/runs', $this->successPayload())->assertCreated();
$this->getJson('/api/gita-order-scrapes/latest')->assertOk();
$this->getJson('/api/gita-order-scrapes/items?tab_status=to_ship&match_status=matched')->assertOk();
```

- [ ] **Step 2: Verify RED**

Run: `vendor\bin\phpunit tests\Feature\GitaOrderScrapeControllerTest.php`

Expected: FAIL because order routes and classes are missing.

- [ ] **Step 3: Implement service, controller, config, and routes**

Use terminal statuses `success`, `needs_login`, and `failed`; tab statuses `to_ship`, `shipped`, and `completed`; and match statuses `matched`, `unmatched`, and `duplicate_master_sku`. `store()` compares the bearer token to `config('gita_order_scraper.ingest_token')` using `hash_equals`. The service validates every field, rejects duplicate seller-order-ID + seller-SKU + variant-label identities before transaction insert, and matches only exact `stock_master.internal_sku`. It never writes Stock Master.

Add public routes `GET /api/gita-order-scrapes/latest` and `GET /api/gita-order-scrapes/items`; validate `tab_status`, `match_status`, `page >= 1`, and `1 <= per_page <= 100`. Return no buyer-related fields.

- [ ] **Step 4: Retire stock HTTP routes and verify GREEN**

Remove `gita-stock-scrapes` routes from `backend/routes/api.php` but do not drop historical stock tables. Run `vendor\bin\phpunit tests\Feature\GitaOrderScrapeControllerTest.php`.

Expected: PASS with unchanged `stock_master.stock_qty` and zero writes to mapping/cache/sync-log tables present in the test database.

- [ ] **Step 5: Commit**

Run: `git add backend/config/gita_order_scraper.php backend/app/Services/GitaOrderScrapeService.php backend/app/Http/Controllers/GitaOrderScrapeController.php backend/routes/api.php backend/tests/Feature/GitaOrderScrapeControllerTest.php` then `git commit -m 'feat: record read-only Gita order collections'`.

## Task 3: Build The Fixture-Driven Order Parser

**Files:**
- Create: `tools/gita-order-scraper/src/orders.js`
- Create: `tools/gita-order-scraper/tests/orders.test.js`
- Create: `tools/gita-order-scraper/tests/fixtures/login.html`
- Create: `tools/gita-order-scraper/tests/fixtures/orders-page-1.html`
- Create: `tools/gita-order-scraper/tests/fixtures/orders-invalid.html`

**Produces:** `detectOrderPageState(document)`, `extractOrderItems(document, tabStatus)`, and `hasNextOrderPage(document)`.

- [ ] **Step 1: Write the failing parser test with no buyer data**

```js
assert.deepEqual(extractOrderItems(document, 'to_ship'), [{
  sellerOrderId: '260808T15MHC24',
  tabStatus: 'to_ship',
  sellerSku: 'INT-40908729245-SAGEE',
  productTitle: 'PARIS LEGEND HIJABERIES Segiempat',
  variantLabel: 'Sagee',
  quantity: 1
}])
```

- [ ] **Step 2: Verify RED**

Run: `node --test tools/gita-order-scraper/tests/orders.test.js`

Expected: FAIL with missing `src/orders.js`.

- [ ] **Step 3: Implement strict parse behavior**

Require a non-blank order ID, seller SKU, product title, and positive integer quantity for every line. Preserve seller SKU exact text, reject page-local duplicate line identities, and return `needs_login` when login, CAPTCHA, or verification markers exist. Parse the seller SKU only, never the adjacent `P...` product ID.

- [ ] **Step 4: Verify GREEN and commit**

Run: `node --test tools/gita-order-scraper/tests/orders.test.js`

Expected: PASS for login, exact SKU, invalid line, duplicate line, and next-page tests.

Run: `git add tools/gita-order-scraper/src/orders.js tools/gita-order-scraper/tests` then `git commit -m 'feat: parse read-only Gita order lines'`.

## Task 4: Implement Persistent Browser Traversal

**Files:**
- Create: `tools/gita-order-scraper/src/config.js`
- Create: `tools/gita-order-scraper/src/client.js`
- Create: `tools/gita-order-scraper/src/cli.js`
- Create: `tools/gita-order-scraper/tests/config.test.js`
- Create: `tools/gita-order-scraper/tests/client.test.js`
- Create: `tools/gita-order-scraper/tests/cli.test.js`
- Modify: `package.json`

**Consumes:** `GITA_ORDER_SCRAPER_API_BASE_URL`, `GITA_ORDER_SCRAPER_INGEST_TOKEN`, `GITA_ORDER_SCRAPER_START_URL`, and `GITA_ORDER_SCRAPER_PROFILE_DIR`.

**Produces:** `runOrderScrape(config, dependencies)`, `npm run gita-order-scrape`, and `npm run test:gita-order-scraper`.

- [ ] **Step 1: Write failing three-tab/pagination traversal tests**

```js
const result = await runOrderScrape(config, workerDependencies({
  to_ship: [pageOne, pageTwo],
  shipped: [pageOne],
  completed: [pageOne]
}))
assert.deepEqual(result, { status: 'success', itemCount: 4 })
assert.equal(dependencies.posted.length, 1)
```

- [ ] **Step 2: Verify RED**

Run: `node --test tools/gita-order-scraper/tests/*.test.js`

Expected: FAIL because the worker modules and scripts do not exist.

- [ ] **Step 3: Implement one-payload terminal flow**

Use tab sequence `to_ship`/`Perlu Dikirim`, `shipped`/`Dikirim`, and `completed`/`Selesai`. For each tab: select the tab, parse the current page, follow next pages until disabled, and append lines to one in-memory collection. Deduplicate across all tabs before posting. On login, parser, tab, page, or delivery failure post one `needs_login` or `failed` payload with no items. Close the browser context in `finally`.

- [ ] **Step 4: Add protected delivery and verify GREEN**

POST JSON to `/gita-order-scrapes/runs` with the bearer token and 30-second timeout. Run `npm run test:gita-order-scraper`.

Expected: PASS for config validation, login, parser failure, three tabs, pagination, cross-tab dedupe, one success post, and context closure.

- [ ] **Step 5: Commit**

Run: `git add package.json tools/gita-order-scraper` then `git commit -m 'feat: collect read-only Gita order tabs'`.

## Task 5: Calibrate Against The Authenticated Seller Centre DOM

**Files:**
- Modify: `tools/gita-order-scraper/src/orders.js`
- Modify: `tools/gita-order-scraper/tests/fixtures/orders-page-1.html`
- Modify: `tools/gita-order-scraper/tests/orders.test.js`

**Produces:** verified non-destructive selectors for the three tabs, order card, item line, SKU text, quantity, and pagination control.

- [ ] **Step 1: Open the visible worker browser and authenticate manually**

Run: `npm run gita-order-scrape`

Expected: a visible Chromium profile opens at `GITA_ORDER_SCRAPER_START_URL`. The human completes login, CAPTCHA, and MFA without sharing any secret.

- [ ] **Step 2: Inspect only required non-personal structural fields**

Identify a stable selector or accessible role for Perlu Dikirim, Dikirim, Selesai, an order ID, item title, variant/SKU string, quantity, an enabled next page, and the disabled final page. Do not save customer content or raw page HTML.

- [ ] **Step 3: Add the observed SKU form to the parser test**

```js
assert.equal(
  extractSellerSku('Variasi: Sagee [P40908729245 INT-40908729245-SAGEE]'),
  'INT-40908729245-SAGEE'
)
```

Reject a row if the seller SKU cannot be isolated exactly. Do not fall back to `P...`.

- [ ] **Step 4: Verify calibration and one supervised run**

Run: `npm run test:gita-order-scraper` then `npm run gita-order-scrape`.

Expected: test suite passes and the terminal result is one complete `success` run; no Seller Centre changing action is clicked.

## Task 6: Add Pesanan Gita UI And Retire Stock UI

**Files:**
- Create: `frontend/src/pages/GitaOrderScrapeReport.vue`
- Create: `frontend/src/pages/gitaOrderScrapeState.js`
- Create: `frontend/tests/gitaOrderScrapeState.test.js`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`
- Delete: `frontend/src/pages/GitaStockScrapeReport.vue`
- Delete: `frontend/src/pages/gitaStockScrapeState.js`
- Delete: `frontend/tests/gitaStockScrapeState.test.js`

**Produces:** `/marketplace/gita-orders` and a compatibility redirect from `/marketplace/gita-stock`.

- [ ] **Step 1: Write failing query-state test**

```js
assert.deepEqual(buildGitaOrderScrapeQuery({
  matchStatus: 'matched', tabStatus: 'to_ship', page: 2
}), { match_status: 'matched', tab_status: 'to_ship', page: 2 })
```

- [ ] **Step 2: Verify RED and implement the report**

Run: `node --test tests/gitaOrderScrapeState.test.js`

Expected: FAIL because state module is absent.

Add public `gitaOrderScrapeLatest()` and `gitaOrderScrapeItems(params)` service calls. Render run counters for line count, total quantity, and the three match states; filters for tab and match status; and a paginated table with order ID, status, SKU, title, variant, quantity, master ID, match status, and capture time. Add no write control or buyer field. Label the menu `Pesanan Gita`.

- [ ] **Step 3: Verify GREEN, build, publish, and commit**

Run: `npm --prefix frontend test` then `npm --prefix frontend run build`.

Expected: all tests pass and build exits `0`.

Run: `Copy-Item -Path frontend\dist\index.html -Destination backend\public\index.html -Force` and `Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets -Force`.

Verify with `Invoke-WebRequest http://agnishopbjm-laravel.test/marketplace/gita-orders -UseBasicParsing` and `Invoke-WebRequest http://agnishopbjm-laravel.test/marketplace/gita-stock -UseBasicParsing`.

Expected: both return `200`; the legacy route client-side redirects to Pesanan Gita.

Run: `git add frontend/src frontend/tests backend/public/index.html backend/public/assets` then `git commit -m 'feat: report read-only Gita order SKU matches'`.

## Task 7: Retire Stock Collector Code And Document Operation

**Files:**
- Delete: `tools/gita-stock-scraper/`
- Delete: `backend/app/Services/GitaStockScrapeService.php`
- Delete: `backend/app/Http/Controllers/GitaStockScrapeController.php`
- Delete: `backend/config/gita_stock_scraper.php`
- Delete: `backend/tests/Feature/GitaStockScrapeControllerTest.php`
- Modify: `package.json`
- Create: `tools/gita-order-scraper/README.md`

- [ ] **Step 1: Write the failing root-script regression test**

```js
assert.equal(packageJson.scripts['gita-order-scrape'], 'node tools/gita-order-scraper/src/cli.js')
assert.equal(packageJson.scripts['gita-stock-scrape'], undefined)
```

- [ ] **Step 2: Verify RED, retire obsolete code, and write operator documentation**

Run: `node --test tools/gita-order-scraper/tests/package.test.js`

Expected: FAIL because the stock command exists.

Delete only obsolete stock collector source/API/UI paths. Do not create a migration that drops existing `gita_stock_scrape_*` tables; they remain inert historical data. The README documents configuration names, `GITA_ORDER_SCRAPER_HEADLESS=false`, `npm run gita-order-scrape`, manual Seller Centre login, three-tab collection, terminal results, and the no-secret/no-mutation rules.

- [ ] **Step 3: Run complete verification and commit**

Run: `npm run test:gita-order-scraper`, `npm --prefix frontend test`, `npm --prefix frontend run build`, and `backend\vendor\bin\phpunit`.

Expected: every command exits `0`. Before and after the supervised run, compare relevant tables and confirm no change to `stock_master`, mappings, Shopee/TikTok caches, or `marketplace_sync_logs`.

Run: `git add package.json tools/gita-order-scraper tools/gita-stock-scraper backend/app backend/config backend/tests docs/superpowers/specs/2026-08-09-gita-order-read-only-collector-design.md` then `git commit -m 'refactor: replace Gita stock collector with order collector'`.

## Plan Self-Review

- Spec coverage: Tasks 1-2 provide separate immutable storage, exact matching, protected ingestion, public reports, terminal outcomes, and no stock mutation. Tasks 3-5 provide strict parser behavior, three tabs, pagination, human authentication, and calibrated live selectors. Task 6 provides the read-only report. Task 7 removes the obsolete stock collector path and documents operation.
- Completeness review: live selectors are intentionally calibrated in Task 5 because authenticated Seller Centre DOM is not available in source control; the required fields and no-partial-success policy are explicit.
- Type consistency: `seller_order_id`, `tab_status`, `seller_sku`, `product_title`, `variant_label`, `quantity`, `match_status`, and `captured_at` are consistent across storage, worker payload, API, and UI.
