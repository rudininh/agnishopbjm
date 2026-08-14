# Gita Stock Read-Only Scraper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect Gita Collection BJM Seller Centre variant stock through a locally authenticated, manual browser run, persist an exact-SKU read-only comparison, and expose its history without changing marketplace or Stock Master quantities.

**Architecture:** A Node.js Playwright worker runs on the computer that owns the Gita browser profile and posts a complete success snapshot or a terminal failure status to Laravel. Laravel validates the worker bearer token, persists immutable scrape run/item history, matches only exact `stock_master.internal_sku` values, and provides authenticated read-only report endpoints. The Vue report consumes only GET endpoints; no application route starts the browser worker or mutates stock.

**Tech Stack:** Node.js native test runner, Playwright, Laravel 11, PHPUnit 10, SQLite in-memory tests, Vue 3, Axios, Vite.

## Global Constraints

- Phase one is read-only: do not update Gita Seller Centre, `stock_master`, Shopee cache tables, TikTok cache tables, `sku_mappings`, or `marketplace_sync_logs`.
- Never type credentials, solve CAPTCHA, submit MFA, or attempt retries around authentication prompts.
- The Gita browser profile, worker token, and backend token are local environment values; do not add them to Git, database rows, frontend code, or logs.
- Match each captured SKU by trimmed, exact, case-sensitive equality with `stock_master.internal_sku`; never match product/variant names or guessed SKUs.
- Only a complete successful worker capture may persist items. `needs_login` and `failed` runs must contain no items.
- GET report routes require `auth:sanctum`; the worker POST route uses only its dedicated bearer token.

---

## File Structure

| Path | Responsibility |
| --- | --- |
| `package.json` | Root worker test and CLI scripts plus the Playwright development dependency. |
| `.gitignore` | Excludes the local Gita persistent browser profile. |
| `tools/gita-stock-scraper/src/config.js` | Validates local worker environment values and configures the browser/profile paths. |
| `tools/gita-stock-scraper/src/inventory.js` | Detects login/access pages and extracts normalized Gita SKU/stock rows from the configured inventory table. |
| `tools/gita-stock-scraper/src/client.js` | Sends terminal worker run payloads to Laravel with the dedicated bearer token. |
| `tools/gita-stock-scraper/src/cli.js` | Launches the local browser worker and composes terminal run outcomes. |
| `tools/gita-stock-scraper/tests/*` | Native Node unit tests and sanitized fixtures; no live Seller Centre tests. |
| `tools/gita-stock-scraper/README.md` | Operator setup, manual login, run, and recovery instructions. |
| `backend/config/gita_stock_scraper.php` | Reads the backend ingest token and bounded request limits from environment. |
| `backend/database/migrations/2026_08_08_000001_create_gita_stock_scrape_tables.php` | Creates immutable scrape run/item tables and lookup indexes. |
| `backend/app/Services/GitaStockScrapeService.php` | Validates terminal capture semantics, exact-SKU matches, transactional persistence, and read models. |
| `backend/app/Http/Controllers/GitaStockScrapeController.php` | Enforces worker token and exposes authenticated read-only JSON endpoints. |
| `backend/routes/api.php` | Registers the worker POST and report GET routes. |
| `backend/tests/Feature/GitaStockScrapeControllerTest.php` | Covers authorization, validation, persistence, matching, reports, and no-mutation regression cases. |
| `backend/.env.example` | Documents the backend worker-ingest token variable without a value. |
| `frontend/src/services/index.js` | Adds read-only report API methods. |
| `frontend/src/pages/GitaStockScrapeReport.vue` | Presents run summary, filters, and paginated read-only scrape rows. |
| `frontend/src/pages/gitaStockScrapeState.js` | Builds validated read-only report query parameters and display labels. |
| `frontend/src/router/index.js` | Registers `/marketplace/gita-stock`. |
| `frontend/src/components/Navbar.vue` | Adds a navigation link to the report. |
| `frontend/tests/gitaStockScrapeState.test.js` | Covers pure read-only query/display state without an Axios mock. |

### Task 1: Establish the Local Worker Contract and Safe Configuration

**Files:**
- Modify: `package.json`
- Modify: `.gitignore`
- Create: `tools/gita-stock-scraper/src/config.js`
- Create: `tools/gita-stock-scraper/tests/config.test.js`
- Create: `tools/gita-stock-scraper/README.md`

**Interfaces:**
- Produces: `loadWorkerConfig(env): WorkerConfig`
- `WorkerConfig` contains `apiBaseUrl`, `ingestToken`, `inventoryUrl`, `profileDir`, `headless`, and `timeoutMs`.
- Consumes later: every worker invocation receives only a validated `WorkerConfig`.

- [ ] **Step 1: Write the failing configuration tests**

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import { loadWorkerConfig } from '../src/config.js'

test('requires an ingestion URL, token, inventory URL, and profile directory', () => {
  assert.throws(() => loadWorkerConfig({}), /GITA_SCRAPER_API_BASE_URL/)
})

test('returns a normalized local profile configuration', () => {
  const config = loadWorkerConfig({
    GITA_SCRAPER_API_BASE_URL: 'http://127.0.0.1:8000/api/',
    GITA_SCRAPER_INGEST_TOKEN: 'test-token',
    GITA_SCRAPER_INVENTORY_URL: 'https://seller.example/inventory',
    GITA_SCRAPER_PROFILE_DIR: '.profile',
    GITA_SCRAPER_HEADLESS: 'false'
  })

  assert.equal(config.apiBaseUrl, 'http://127.0.0.1:8000/api')
  assert.equal(config.headless, false)
})
```

- [ ] **Step 2: Run the worker test to verify it fails**

Run: `node --test tools/gita-stock-scraper/tests/config.test.js`

Expected: FAIL because `tools/gita-stock-scraper/src/config.js` does not exist.

- [ ] **Step 3: Add the minimal configuration implementation and scripts**

Implement `loadWorkerConfig` to reject empty values, canonicalize the API base URL by removing its trailing slash, resolve the profile directory, parse only `true`/`false` for headless mode, and bound the timeout to 10-120 seconds. Update root `package.json` with:

```json
{
  "scripts": {
    "test:gita-stock-scraper": "node --test tools/gita-stock-scraper/tests/*.test.js",
    "gita-stock-scrape": "node tools/gita-stock-scraper/src/cli.js"
  }
}
```

Install Playwright with `npm install --save-dev playwright`, commit its root lockfile update, and append this exact ignore entry:

```gitignore
tools/gita-stock-scraper/.profile/
```

Document the four required `GITA_SCRAPER_*` values and state that profile creation/login happens manually with a visible browser.

- [ ] **Step 4: Run the worker test to verify it passes**

Run: `npm run test:gita-stock-scraper`

Expected: PASS with the two configuration tests and no network/browser activity.

- [ ] **Step 5: Commit the safe worker configuration**

```bash
git add package.json package-lock.json .gitignore tools/gita-stock-scraper
git commit -m "feat: add Gita scraper worker configuration"
```

### Task 2: Extract Inventory Rows and Detect Authentication Stops

**Files:**
- Create: `tools/gita-stock-scraper/src/inventory.js`
- Create: `tools/gita-stock-scraper/tests/inventory.test.js`
- Create: `tools/gita-stock-scraper/tests/fixtures/inventory.html`
- Create: `tools/gita-stock-scraper/tests/fixtures/login.html`
- Create: `tools/gita-stock-scraper/tests/fixtures/invalid-inventory.html`

**Interfaces:**
- Produces: `detectInventoryState(document): 'inventory' | 'needs_login' | 'invalid'`
- Produces: `extractInventoryRows(document): Array<{sku: string, stock: number, gitaProductId: string|null, gitaVariantId: string|null}>`
- Consumes later: `cli.js` accepts only `inventory` state and non-empty, unique valid rows.

- [ ] **Step 1: Write failing parser tests using sanitized fixtures**

```js
test('returns needs_login for a login or verification screen', () => {
  const document = loadFixture('login.html')
  assert.equal(detectInventoryState(document), 'needs_login')
})

test('extracts trimmed SKU, integer stock, and visible IDs from inventory rows', () => {
  const rows = extractInventoryRows(loadFixture('inventory.html'))
  assert.deepEqual(rows, [{
    sku: 'GITA-RED-S',
    stock: 12,
    gitaProductId: '1001',
    gitaVariantId: '2001'
  }])
})

test('rejects blank SKUs, negative stocks, and duplicate SKUs', () => {
  assert.throws(() => extractInventoryRows(loadFixture('invalid-inventory.html')), /SKU|stock|duplicate/i)
})
```

- [ ] **Step 2: Run the parser tests to verify they fail**

Run: `node --test tools/gita-stock-scraper/tests/inventory.test.js`

Expected: FAIL because the inventory module and fixtures do not exist.

- [ ] **Step 3: Implement semantic, read-only extraction**

Implement browser-side extraction from a configured inventory table using read-only DOM APIs only. The implementation must wait for an inventory table or known authentication prompt, paginate only with a next-page navigation control, and never call click handlers for edit/save/publish/bulk-update controls. It must normalize a row to trimmed SKU plus a non-negative base-10 integer quantity, reject duplicate SKUs for the entire capture, and preserve visible product/variant IDs only when present.

During the first operator-supervised run, inspect the authenticated Seller Centre DOM and set the stable table/column selectors in `inventory.js`. Store only selector names in source; do not save HTML, screenshots containing personal data, cookies, or network responses as fixtures.

- [ ] **Step 4: Run the parser tests to verify they pass**

Run: `npm run test:gita-stock-scraper`

Expected: PASS for login detection, row extraction, and invalid-row rejection.

- [ ] **Step 5: Commit the inventory extractor**

```bash
git add tools/gita-stock-scraper/src/inventory.js tools/gita-stock-scraper/tests
git commit -m "feat: extract read-only Gita inventory rows"
```

### Task 3: Submit Only Terminal Worker Runs to Laravel

**Files:**
- Create: `tools/gita-stock-scraper/src/client.js`
- Create: `tools/gita-stock-scraper/src/cli.js`
- Create: `tools/gita-stock-scraper/tests/client.test.js`
- Create: `tools/gita-stock-scraper/tests/cli.test.js`

**Interfaces:**
- Produces: `postRun(config, payload, fetchImpl): Promise<object>`
- Produces: `runScrape(config, dependencies): Promise<{status: 'success'|'needs_login'|'failed', itemCount: number}>`
- Consumes: `POST {apiBaseUrl}/gita-stock-scrapes/runs` with a bearer token.
- Payload contract:

```json
{
  "status": "success",
  "started_at": "2026-08-08T10:00:00.000Z",
  "finished_at": "2026-08-08T10:01:00.000Z",
  "items": [{
    "sku": "GITA-RED-S",
    "stock": 12,
    "gita_product_id": "1001",
    "gita_variant_id": "2001",
    "captured_at": "2026-08-08T10:00:45.000Z"
  }]
}
```

- [ ] **Step 1: Write failing client and CLI tests**

```js
test('posts a complete successful run with the dedicated bearer token', async () => {
  const request = await captureRequest(() => postRun(config, successPayload, fakeFetch))
  assert.equal(request.headers.authorization, 'Bearer worker-token')
  assert.equal(request.url, 'https://agnishop.test/api/gita-stock-scrapes/runs')
})

test('reports needs_login without posting item rows', async () => {
  const result = await runScrape(config, fakeBrowserShowingLogin)
  assert.equal(result.status, 'needs_login')
  assert.equal(result.itemCount, 0)
  assert.deepEqual(fakeBrowserShowingLogin.posted.items, undefined)
})

test('reports parser and navigation failures as failed without a partial payload', async () => {
  const result = await runScrape(config, fakeBrowserThrowingOnPageTwo)
  assert.equal(result.status, 'failed')
  assert.equal(result.itemCount, 0)
})
```

- [ ] **Step 2: Run the worker tests to verify they fail**

Run: `node --test tools/gita-stock-scraper/tests/client.test.js tools/gita-stock-scraper/tests/cli.test.js`

Expected: FAIL because the API client and CLI modules do not exist.

- [ ] **Step 3: Implement terminal-status submission and browser orchestration**

Implement `postRun` with `fetch`, JSON-only request/response handling, a 30-second network timeout, and a generic sanitized error for unexpected responses. Implement `runScrape` with Playwright's persistent context, `headless: false` by default, navigation to `inventoryUrl`, inventory-state detection, complete pagination, and `try/finally` browser cleanup.

The only permitted POST outcomes are:

```js
{ status: 'success', started_at, finished_at, items }
{ status: 'needs_login', started_at, finished_at, message: 'Login Gita diperlukan.' }
{ status: 'failed', started_at, finished_at, message: 'Pengambilan stok Gita gagal.' }
```

`success` requires a non-empty full item array. `needs_login` and `failed` omit `items` entirely. The CLI prints only status, run duration, and count; it must never print environment values, URLs with credentials, HTML, cookies, or response bodies.

- [ ] **Step 4: Run the full worker suite to verify it passes**

Run: `npm run test:gita-stock-scraper`

Expected: PASS for configuration, parser, API-client, and terminal-status tests.

- [ ] **Step 5: Commit the local worker run flow**

```bash
git add tools/gita-stock-scraper
git commit -m "feat: submit Gita scrape run snapshots"
```

### Task 4: Persist Immutable Runs and Exact Stock-Master Matches

**Files:**
- Create: `backend/config/gita_stock_scraper.php`
- Create: `backend/database/migrations/2026_08_08_000001_create_gita_stock_scrape_tables.php`
- Create: `backend/app/Services/GitaStockScrapeService.php`
- Create: `backend/tests/Feature/GitaStockScrapeServiceTest.php`
- Modify: `backend/.env.example`

**Interfaces:**
- Produces: `GitaStockScrapeService::record(array $payload): array`
- Produces: `GitaStockScrapeService::latestRun(): ?array`
- Produces: `GitaStockScrapeService::items(array $filters, int $page, int $perPage): array`
- `record` returns `['run_id' => int, 'status' => string, 'summary' => array]` and does not call a marketplace service.

- [ ] **Step 1: Write failing persistence and no-mutation tests**

```php
public function test_successful_capture_persists_exact_match_without_updating_stock_master(): void
{
    $this->createGitaScrapeTables();
    DB::table('stock_master')->insert(['internal_sku' => 'GITA-RED-S', 'stock_qty' => 7]);

    $result = app(GitaStockScrapeService::class)->record($this->successPayload([
        ['sku' => 'GITA-RED-S', 'stock' => 12],
    ]));

    $this->assertSame('success', $result['status']);
    $this->assertDatabaseHas('gita_stock_scrape_items', [
        'sku' => 'GITA-RED-S', 'stock_master_id' => 1, 'match_status' => 'matched', 'stock' => 12,
    ]);
    $this->assertDatabaseHas('stock_master', ['id' => 1, 'stock_qty' => 7]);
}

public function test_duplicate_source_sku_rejects_the_entire_success_payload(): void
{
    $this->expectException(ValidationException::class);
    app(GitaStockScrapeService::class)->record($this->successPayload([
        ['sku' => 'GITA-RED-S', 'stock' => 12],
        ['sku' => 'GITA-RED-S', 'stock' => 9],
    ]));
}
```

- [ ] **Step 2: Run the backend service test to verify it fails**

Run: `php artisan test --filter=GitaStockScrapeServiceTest`

Expected: FAIL because the service and persistence tables do not exist.

- [ ] **Step 3: Create schema, config, and transactional service**

Create `gita_stock_scrape_runs` with `id`, `status`, `started_at`, `finished_at`, nullable `message`, integer summary counters (`item_count`, `matched_count`, `unmatched_count`, `duplicate_master_count`, `changed_count`), and timestamps. Create `gita_stock_scrape_items` with a foreign key to the run, nullable indexed `stock_master_id` without a database foreign key, `sku`, `stock`, nullable Gita product/variant IDs, nullable previous captured stock, `match_status`, `captured_at`, and timestamps. Index run status/time, item SKU, and stock-master ID.

`record` must:

1. Validate exactly one of the terminal shapes from Task 3.
2. Insert `needs_login` and `failed` runs without items.
3. For `success`, reject empty items, invalid timestamps, blank SKU, non-integer/negative stock, and duplicate source SKUs before opening the transaction.
4. Resolve each trimmed Gita SKU by `stock_master.internal_sku` exact equality. Set `matched`, `unmatched`, or `duplicate_master_sku` without fallback fields.
5. Load each matched SKU's prior successful item to derive `previous_stock` and `changed_count`.
6. Insert the run and all item rows in one transaction, without touching any existing stock, mapping, cache, or sync-log table.

Add `GITA_STOCK_SCRAPER_INGEST_TOKEN=` and bounded `GITA_STOCK_SCRAPER_MAX_ITEMS=5000` entries to `backend/.env.example`; config returns the trimmed token and a 1-5000 item limit.

- [ ] **Step 4: Run the service tests to verify they pass**

Run: `php artisan test --filter=GitaStockScrapeServiceTest`

Expected: PASS for terminal statuses, exact match classifications, previous-stock reporting, duplicate rejection, and no Stock Master mutation.

- [ ] **Step 5: Commit the persistence layer**

```bash
git add backend/config/gita_stock_scraper.php backend/database/migrations/2026_08_08_000001_create_gita_stock_scrape_tables.php backend/app/Services/GitaStockScrapeService.php backend/tests/Feature/GitaStockScrapeServiceTest.php backend/.env.example
git commit -m "feat: persist read-only Gita stock snapshots"
```

### Task 5: Add Token-Protected Worker Ingestion and Authenticated Reports

**Files:**
- Create: `backend/app/Http/Controllers/GitaStockScrapeController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/GitaStockScrapeControllerTest.php`

**Interfaces:**
- Produces: `POST /api/gita-stock-scrapes/runs`
- Produces: `GET /api/gita-stock-scrapes/latest`
- Produces: `GET /api/gita-stock-scrapes/items?status=&changed_only=&page=&per_page=`
- Worker POST accepts only the `GITA_STOCK_SCRAPER_INGEST_TOKEN` bearer token.
- Report GET routes require `auth:sanctum`.

- [ ] **Step 1: Write failing endpoint authorization and report tests**

```php
public function test_worker_run_endpoint_rejects_a_missing_or_invalid_bearer_token(): void
{
    config(['gita_stock_scraper.ingest_token' => 'worker-secret']);

    $this->postJson('/api/gita-stock-scrapes/runs', $this->successPayload())
        ->assertUnauthorized();

    $this->withToken('wrong')->postJson('/api/gita-stock-scrapes/runs', $this->successPayload())
        ->assertUnauthorized();
}

public function test_authenticated_report_returns_only_read_only_scrape_rows(): void
{
    $token = User::factory()->create()->createToken('test')->plainTextToken;
    $this->seedSuccessfulGitaRun();

    $this->withToken($token)->getJson('/api/gita-stock-scrapes/items?changed_only=1')
        ->assertOk()
        ->assertJsonPath('items.0.sku', 'GITA-RED-S')
        ->assertJsonPath('items.0.stock', 12);
}
```

- [ ] **Step 2: Run the controller test to verify it fails**

Run: `php artisan test --filter=GitaStockScrapeControllerTest`

Expected: FAIL because the routes and controller do not exist.

- [ ] **Step 3: Implement request guard, controller, and routes**

Implement a private `authorizedWorker(Request $request): bool` guard that rejects a missing configured token and uses `hash_equals` against the trimmed bearer token. Do not put this token into a middleware alias or a response.

Register:

```php
Route::post('gita-stock-scrapes/runs', [GitaStockScrapeController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('gita-stock-scrapes/latest', [GitaStockScrapeController::class, 'latest']);
    Route::get('gita-stock-scrapes/items', [GitaStockScrapeController::class, 'items']);
});
```

`store` returns 201 for a persisted success/terminal run, 401 for an unauthorized worker, and 422 for a malformed or partial capture. `latest` returns the newest run or a `{ "data": null }` response. `items` accepts only a valid `match_status`, `changed_only` boolean, `page >= 1`, and `per_page` bounded to 100. Neither controller method may instantiate or call `MarketplaceSyncService`, `OmnichannelController`, or a stock update method.

- [ ] **Step 4: Run controller and service tests to verify they pass**

Run: `php artisan test --filter=GitaStockScrape`

Expected: PASS for bearer validation, authenticated report access, pagination/filter validation, and read-only regression coverage.

- [ ] **Step 5: Commit the HTTP boundary**

```bash
git add backend/app/Http/Controllers/GitaStockScrapeController.php backend/routes/api.php backend/tests/Feature/GitaStockScrapeControllerTest.php
git commit -m "feat: expose read-only Gita scrape reports"
```

### Task 6: Present the Read-Only History in AgniShop

**Files:**
- Modify: `frontend/src/services/index.js`
- Create: `frontend/src/pages/GitaStockScrapeReport.vue`
- Create: `frontend/src/pages/gitaStockScrapeState.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`
- Create: `frontend/tests/gitaStockScrapeState.test.js`

**Interfaces:**
- Produces: `omnichannelService.gitaStockScrapeLatest()` and `omnichannelService.gitaStockScrapeItems(params)`.
- Produces: `buildGitaStockScrapeQuery(filters): object` and `gitaMatchStatusLabel(status): string`.
- Produces: route `/marketplace/gita-stock`.
- Consumes: only Task 5 GET responses.

- [ ] **Step 1: Write the failing read-only report-state test**

Create `frontend/tests/gitaStockScrapeState.test.js` in the existing native Node test style:

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import { buildGitaStockScrapeQuery, gitaMatchStatusLabel } from '../src/pages/gitaStockScrapeState.js'

test('builds only read-only item filters', () => {
  assert.deepEqual(buildGitaStockScrapeQuery({
    matchStatus: 'matched', changedOnly: true, page: 2
  }), { match_status: 'matched', changed_only: 1, page: 2 })
})

test('uses a visible label for every persisted match status', () => {
  assert.equal(gitaMatchStatusLabel('duplicate_master_sku'), 'SKU Stock Master ganda')
})
```

- [ ] **Step 2: Run the frontend test to verify it fails**

Run: `node --test frontend/tests/gitaStockScrapeState.test.js`

Expected: FAIL because the report-state module does not exist.

- [ ] **Step 3: Implement the read-only report view**

Add two service methods that call only `GET /gita-stock-scrapes/latest` and `GET /gita-stock-scrapes/items`. Implement `buildGitaStockScrapeQuery` so it can produce only the GET filter keys accepted by Task 5, and use it in `GitaStockScrapeReport.vue` when loading the paginated list. The view loads the latest run and items on mount, provides filters for match status and changed-only, shows terminal status/message/counts, and displays SKU, captured stock, previous stock, Stock Master ID, match status, and captured time.

Do not add a run button, mutation button, sync button, stock field editor, modal submit action, or any POST/PUT/DELETE request. Register the route and add a Navbar link labelled `Stok Gita` in the marketplace navigation group.

- [ ] **Step 4: Run the frontend test and production build to verify they pass**

Run: `npm --prefix frontend test`

Expected: PASS including `gitaStockScrapeState.test.js`.

Run: `npm --prefix frontend run build`

Expected: Vite production build completes without errors.

- [ ] **Step 5: Commit the report UI**

```bash
git add frontend/src/services/index.js frontend/src/pages/GitaStockScrapeReport.vue frontend/src/pages/gitaStockScrapeState.js frontend/src/router/index.js frontend/src/components/Navbar.vue frontend/tests/gitaStockScrapeState.test.js
git commit -m "feat: show Gita stock scrape history"
```

### Task 7: Verify the Read-Only Boundary With an Operator-Supervised Run

**Files:**
- Modify: `tools/gita-stock-scraper/README.md`
- Modify: `docs/superpowers/specs/2026-08-08-gita-stock-read-only-scraper-design.md`

**Interfaces:**
- Consumes: the worker CLI from Task 3, backend endpoints from Task 5, and report route from Task 6.
- Produces: a documented, repeatable local verification checklist with no credential values.

- [ ] **Step 1: Add a failing verification checklist assertion to the worker documentation test**

Extend `tools/gita-stock-scraper/tests/config.test.js` to read the local worker README and assert that it documents all three terminal outcomes and does not prescribe stored credentials:

```js
test('operator guide documents manual authentication and terminal run outcomes', async () => {
  const guide = await readFile(new URL('../README.md', import.meta.url), 'utf8')
  assert.match(guide, /needs_login/)
  assert.match(guide, /manual login/i)
  assert.doesNotMatch(guide, /password=/i)
})
```

- [ ] **Step 2: Run the documentation test to verify it fails**

Run: `npm run test:gita-stock-scraper`

Expected: FAIL until the operator guide covers the final verification procedure.

- [ ] **Step 3: Document exact operational verification and update the design record**

Add this procedure to the worker README:

```text
1. Set local GITA_SCRAPER_* environment values without adding them to a file tracked by Git.
2. Run npm run gita-stock-scrape with headless mode disabled.
3. Complete Gita Seller Centre login, CAPTCHA, or MFA manually if requested.
4. Confirm the CLI reports success and an item count, or needs_login/failed with zero item count.
5. Sign in to AgniShop and open /marketplace/gita-stock to inspect the immutable run history.
6. Query the database before and after the run to confirm stock_master, sku_mappings, shopee_product_model, tiktok_products, and marketplace_sync_logs have no changed rows from this run.
```

Record the implementation verification command set in the design document's operational-verification section. Do not add production schedules, cron entries, supervisor configuration, or a UI control that starts the worker.

- [ ] **Step 4: Execute the complete automated verification suite**

Run: `npm run test:gita-stock-scraper`

Expected: PASS.

Run: `php artisan test --filter=GitaStockScrape`

Expected: PASS.

Run: `php artisan test`

Expected: PASS for the complete backend suite.

Run: `npm --prefix frontend test`

Expected: PASS.

Run: `npm --prefix frontend run build`

Expected: PASS.

- [ ] **Step 5: Perform a manual, visible browser verification after the operator logs in**

Run: `npm run gita-stock-scrape`

Expected: a `success` run with a non-zero item count, or the explicit `needs_login` result with zero items. Verify the Seller Centre has no edited/published stock and use the report page plus database checks to prove no AgniShop marketplace/Stock Master mutation occurred.

- [ ] **Step 6: Commit the final verification documentation**

```bash
git add tools/gita-stock-scraper/README.md docs/superpowers/specs/2026-08-08-gita-stock-read-only-scraper-design.md
git commit -m "docs: verify Gita scraper read-only operation"
```

## Plan Self-Review

- **Spec coverage:** Tasks 1-3 implement the separate local human-authenticated worker; Tasks 4-5 implement complete snapshot persistence, exact-SKU matching, terminal statuses, bounded bearer-token ingestion, and read-only reports; Task 6 implements the optional read-only AgniShop history UI; Task 7 verifies no phase-one stock mutation and documents operation.
- **No-placeholders scan:** The plan defines concrete files, route names, terminal status payloads, service method names, schema fields, test commands, expected failures, and commit commands. The only operator-provided value is the existing authenticated Seller Centre inventory URL, supplied at runtime through `GITA_SCRAPER_INVENTORY_URL`; it is intentionally not hard-coded because it is account/session dependent.
- **Interface consistency:** Worker `postRun` sends `POST /gita-stock-scrapes/runs`; `GitaStockScrapeService::record` owns persistence; controller GET routes use the same `/gita-stock-scrapes` namespace; Vue calls only those GET routes. All later tasks use the three terminal statuses from Task 3.
