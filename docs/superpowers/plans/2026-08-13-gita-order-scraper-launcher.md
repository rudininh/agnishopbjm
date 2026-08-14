# Gita Order Scraper Launcher PC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a safe `Jalankan Scraper PC` control to `/marketplace/gita-orders` that launches the local read-only Gita order scraper while preventing browser-profile and marketplace-operation collisions.

**Architecture:** Laravel owns launch admission through `MarketplaceOperationLeaseService`, starts the fixed Node CLI through hidden PowerShell, and returns only safe launcher states to Vue. The Node CLI owns the browser profile lock and keeps a lease alive while it collects one terminal run; it releases both safeguards in `finally`. The existing Gita ingestion token remains worker-only and is never exposed to the browser UI or log output.

**Tech Stack:** Laravel 11 / PHP 8.3, Vue 3 Composition API, Vite, Node.js ESM, Playwright, Node built-in test runner, PHPUnit.

## Global Constraints

- The launcher may only execute `tools/gita-order-scraper/src/cli.js` from the project root with the configured Node binary.
- The collector remains read-only: launcher work must not call `GitaOrderStockSyncService`, `MarketplaceSyncService`, stock writes, order-state writes, or mass-upload code.
- Only one `gita_order_scrape` marketplace-operation lease and one Gita profile lock may be active at a time.
- An active non-Gita marketplace lease returns `marketplace_busy`; an active `gita_order_scrape` lease returns `already_running`.
- Lock, lease token, ingest token, cookies, browser profile content, OTP, and CAPTCHA data may not appear in API responses, browser UI, console output, or redirected logs.
- Browser profile stays `tools/gita-order-scraper/.profile`; it is not shared with Gitashop Mass Update.
- The page must show the manual fallback command, but command execution remains guarded by the same CLI lock and lease claim.
- No retry loop may automatically rerun a `needs_login`, `failed`, or `marketplace_busy` collection.
- Publish frontend changes by copying `frontend/dist/index.html` and all `frontend/dist/assets/*` files to `backend/public`.

---

## File Structure

- `backend/config/gita_order_scraper.php` — declares launcher and lease timing configuration without secrets.
- `backend/app/Services/GitaOrderScrapeWorkerLeaseService.php` — maps marketplace-lease outcomes to safe scraper-specific state and exposes claim/renew/release methods.
- `backend/app/Services/GitaOrderScrapeWorkerLauncher.php` — starts the fixed Node command, passes only an opaque lease claim through the child environment, and returns launcher states.
- `backend/app/Http/Controllers/GitaOrderScrapeController.php` — exposes safe launcher wake and worker lease endpoints while preserving protected ingestion.
- `backend/routes/api.php` — routes public wake separately from bearer-protected worker lease operations.
- `backend/tests/Feature/GitaOrderScrapeControllerTest.php` — verifies wake admission, safe busy outcomes, and protected claim lifecycle.
- `tools/gita-order-scraper/src/config.js` — reads optional launcher-provided lease claim and lease timing from environment.
- `tools/gita-order-scraper/src/client.js` — posts claim, renew, and release requests using the existing dedicated ingestion bearer token.
- `tools/gita-order-scraper/src/cli.js` — takes a crash-safe exclusive profile lock, acquires/renews/releases the operation lease, and keeps existing terminal posting semantics.
- `tools/gita-order-scraper/tests/config.test.js` — covers parsed worker-lease configuration without reading a real `.env`.
- `tools/gita-order-scraper/tests/client.test.js` — covers bearer-authenticated lease API calls and no token leakage.
- `tools/gita-order-scraper/tests/cli.test.js` — covers lock contention/staleness, busy-before-browser, lease release, and launcher-claim reuse.
- `frontend/src/services/index.js` — adds the wake request.
- `frontend/src/pages/GitaOrderScrapeReport.vue` — renders launcher button, state messaging, fallback command, and bounded refresh polling.
- `frontend/src/pages/gitaOrderScrapeState.js` — provides safe user-facing launcher-state labels if UI state formatting is split from the component.
- `frontend/tests/gitaOrderScrapeState.test.js` — verifies labels and no secret/browser detail is displayed.
- `backend/.env.example` — documents only non-secret launcher flags.
- `tools/gita-order-scraper/README.md` — documents automatic launch, manual fallback, and exclusive-run behavior.

## Task 1: Add Scraper Lease Configuration And Service

**Files:**
- Modify: `backend/config/gita_order_scraper.php`
- Create: `backend/app/Services/GitaOrderScrapeWorkerLeaseService.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Interfaces:**
- Consumes: `MarketplaceOperationLeaseService::acquire(string $operation, int $seconds): array`, `renew(string $token, int $seconds): bool`, `release(string $token): bool`, and `status(): array`.
- Produces: `GitaOrderScrapeWorkerLeaseService::claim(): array`, `renew(string $token): bool`, and `release(string $token): bool`.
- `claim()` returns `['status' => 'claimed'|'already_running'|'marketplace_busy', 'token' => ?string, 'locked_until_at' => ?string, 'operation' => ?string]`; only `claimed` includes a token.

- [ ] **Step 1: Write failing lease-admission tests**

Add PHPUnit cases that fake/prepare the runtime row and assert:

```php
$this->postJson('/api/gita-order-scrapes/worker/lease')
    ->assertUnauthorized();

$this->withToken('worker-secret')
    ->postJson('/api/gita-order-scrapes/worker/lease')
    ->assertOk()
    ->assertJsonPath('data.status', 'claimed');

$this->withToken('worker-secret')
    ->postJson('/api/gita-order-scrapes/worker/lease')
    ->assertConflict()
    ->assertJsonPath('data.status', 'already_running');
```

Also acquire a non-Gita lease through `MarketplaceOperationLeaseService` and assert a `423` response with status `marketplace_busy` and no raw token.

- [ ] **Step 2: Run the focused test to verify RED**

Run: `vendor/bin/phpunit --filter=GitaOrderScrapeControllerTest`

Expected: FAIL because the worker lease endpoint/service does not exist.

- [ ] **Step 3: Add bounded configuration**

In `backend/config/gita_order_scraper.php`, add:

```php
'local_worker_enabled' => filter_var(env('GITA_ORDER_SCRAPER_LOCAL_WORKER_ENABLED', true), FILTER_VALIDATE_BOOL),
'local_worker_node_binary' => trim((string) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_NODE_BINARY', 'node')) ?: 'node',
'local_worker_alive_seconds' => max(10, min(300, (int) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_ALIVE_SECONDS', 45))),
'worker_lease_seconds' => max(60, min(3600, (int) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS', 900))),
```

Document the four non-secret settings in `backend/.env.example`.

- [ ] **Step 4: Implement the lease service and protected endpoint methods**

Create `GitaOrderScrapeWorkerLeaseService`. Map an already-active `gita_order_scrape` operation to `already_running`; map every other active operation to `marketplace_busy`. Call `MarketplaceOperationLeaseService::acquire('gita_order_scrape', configuredSeconds)` only once per claim.

Add controller methods `claimWorkerLease(Request $request)`, `renewWorkerLease(Request $request)`, and `releaseWorkerLease(Request $request)`. Reuse `authorizedWorker()` so every method requires the existing bearer token. Validate a non-empty `lease_token` body input for renew/release; never serialize it back.

- [ ] **Step 5: Run the focused test to verify GREEN**

Run: `vendor/bin/phpunit --filter=GitaOrderScrapeControllerTest`

Expected: PASS, including claim, same-operation conflict, foreign-operation busy, renew, release, and unauthorized cases.

- [ ] **Step 6: Commit the focused backend lease work**

```powershell
git add backend/config/gita_order_scraper.php backend/app/Services/GitaOrderScrapeWorkerLeaseService.php backend/app/Http/Controllers/GitaOrderScrapeController.php backend/routes/api.php backend/.env.example backend/tests/Feature/GitaOrderScrapeControllerTest.php
git commit -m "feat: guard Gita scraper with marketplace lease"
```

## Task 2: Build the Fixed Local Launcher Endpoint

**Files:**
- Create: `backend/app/Services/GitaOrderScrapeWorkerLauncher.php`
- Modify: `backend/app/Http/Controllers/GitaOrderScrapeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/GitaOrderScrapeControllerTest.php`

**Interfaces:**
- Consumes: `GitaOrderScrapeWorkerLeaseService::claim()` and `release(string $token): bool`.
- Produces: `GitaOrderScrapeWorkerLauncher::wake(): array` returning only `started`, `already_running`, `marketplace_busy`, or `manual_required`, plus optional safe `operation` and `locked_until_at` fields.
- Public route: `POST /api/gita-order-scrapes/worker/wake`.

- [ ] **Step 1: Write failing wake tests**

Add controller tests using a fake launcher instance that assert:

```php
$this->postJson('/api/gita-order-scrapes/worker/wake')
    ->assertOk()
    ->assertJsonPath('data.status', 'started');
```

Cover `already_running`, `marketplace_busy`, and `manual_required`, and assert no response contains a lease token or command string.

- [ ] **Step 2: Run the focused test to verify RED**

Run: `vendor/bin/phpunit --filter=GitaOrderScrapeControllerTest`

Expected: FAIL because the wake route and launcher dependency are absent.

- [ ] **Step 3: Implement `GitaOrderScrapeWorkerLauncher`**

Mirror the safe process-spawn pattern in `backend/app/Services/GitashopMassUploadWorkerLauncher.php` with these differences:

- claim the scraper lease before spawning;
- treat the claim outcome as `already_running` or `marketplace_busy` without spawning;
- build one fixed `Start-Process` command for configured Node and the Gita CLI;
- set `GITA_ORDER_SCRAPER_OPERATION_LEASE_TOKEN` and lease duration only in the child process environment;
- use `-WindowStyle Hidden` and append-safe files `gita-order-scraper-worker.log` and `gita-order-scraper-worker-error.log` under `backend/storage/logs`;
- release the just-claimed lease when `proc_open` cannot start the PowerShell launcher;
- return `manual_required` if launcher disabled, Node/script missing, logs cannot be prepared, or `proc_open` is unavailable.

Do not pass the bearer ingest token through PowerShell; the CLI continues to read it from `backend/.env`.

- [ ] **Step 4: Wire the endpoint and dependency injection**

Inject the launcher into `GitaOrderScrapeController`, add `wakeWorker(): JsonResponse`, and route its public `POST` endpoint adjacent to the existing public report routes. Do not put this endpoint inside the bearer-token worker group because it is triggered by the local dashboard.

- [ ] **Step 5: Run the focused test to verify GREEN**

Run: `vendor/bin/phpunit --filter=GitaOrderScrapeControllerTest`

Expected: PASS with safe launcher results and no leak assertions.

- [ ] **Step 6: Commit the launcher endpoint**

```powershell
git add backend/app/Services/GitaOrderScrapeWorkerLauncher.php backend/app/Http/Controllers/GitaOrderScrapeController.php backend/routes/api.php backend/tests/Feature/GitaOrderScrapeControllerTest.php
git commit -m "feat: launch Gita scraper from local dashboard"
```

## Task 3: Make the Node Scraper Own Lock And Lease Lifecycle

**Files:**
- Modify: `tools/gita-order-scraper/src/config.js`
- Modify: `tools/gita-order-scraper/src/client.js`
- Modify: `tools/gita-order-scraper/src/cli.js`
- Test: `tools/gita-order-scraper/tests/config.test.js`
- Test: `tools/gita-order-scraper/tests/client.test.js`
- Test: `tools/gita-order-scraper/tests/cli.test.js`

**Interfaces:**
- Consumes: protected API endpoints `POST /gita-order-scrapes/worker/lease`, `/lease/renew`, and `/lease/release` using the existing bearer token.
- Produces: `loadOrderWorkerConfig()` additions `operationLeaseToken`, `leaseSeconds`, and `leaseRenewMs`; `acquireOrderScraperLock(lockPath, dependencies?)`; and a CLI entrypoint that always releases acquired resources.

- [ ] **Step 1: Write failing configuration and client tests**

Add config tests for these exact expectations:

```js
assert.equal(config.operationLeaseToken, 'launcher-claim')
assert.equal(config.leaseSeconds, 900)
assert.equal(config.leaseRenewMs, 300000)
```

Add client tests ensuring claim, renew, and release use `Authorization: Bearer <ingest token>` and send only `lease_token` JSON for renew/release.

- [ ] **Step 2: Run the targeted tests to verify RED**

Run: `node --test tools/gita-order-scraper/tests/config.test.js tools/gita-order-scraper/tests/client.test.js`

Expected: FAIL because lease fields and client methods do not exist.

- [ ] **Step 3: Implement configuration and API client methods**

Extend `loadOrderWorkerConfig()` to read optional `GITA_ORDER_SCRAPER_OPERATION_LEASE_TOKEN` and bounded `GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS`. Derive renewal interval as at most half the lease duration and no shorter than 15 seconds. Add `claimOrderScraperLease`, `renewOrderScraperLease`, and `releaseOrderScraperLease` to `client.js` using the existing `apiBaseUrl` and ingest token.

- [ ] **Step 4: Write failing CLI safety tests**

Add tests proving:

```js
await assert.rejects(
  acquireOrderScraperLock(lockPath, { isPidAlive: () => true }),
  /already running/i
)
```

Add a stale-lock test that removes the stale file then acquires it, a `marketplace_busy` test that verifies `launchContext` is never called, and terminal-result tests that assert acquired leases and lock files are released after success and after thrown scrape errors.

- [ ] **Step 5: Run CLI tests to verify RED**

Run: `node --test tools/gita-order-scraper/tests/cli.test.js`

Expected: FAIL because lock/lease lifecycle is not implemented.

- [ ] **Step 6: Implement exclusive lock and lease lifecycle**

In `cli.js`:

- import filesystem and URL helpers needed to resolve an absolute lock path under `backend/storage/app/gita-order-scraper-worker.lock`;
- write the lock atomically with `{ pid, startedAt }`, detect a live PID as `already_running`, and remove malformed/stale locks safely;
- before `runOrderScrape`, use the launcher-supplied lease token when present, otherwise call `claimOrderScraperLease`;
- if claim says `marketplace_busy` or `already_running`, exit before `launchPersistentContext` and print only the sanitized reason;
- renew the claim on an interval while the scrape is running;
- in `finally`, stop the renewal interval, release the lease only if this CLI acquired/received it, and remove its own lock;
- preserve `runOrderScrape` as the unit-testable collection function and preserve its terminal ingestion behavior;
- map new expected conditions to `marketplace_busy` and `already_running` reasons without including token or profile paths.

- [ ] **Step 7: Run all worker tests and syntax check**

Run: `node --check tools/gita-order-scraper/src/cli.js; node --test tools/gita-order-scraper/tests/*.test.js`

Expected: syntax exits `0`; all collector tests pass.

- [ ] **Step 8: Commit the scraper safety work**

```powershell
git add tools/gita-order-scraper/src/config.js tools/gita-order-scraper/src/client.js tools/gita-order-scraper/src/cli.js tools/gita-order-scraper/tests/config.test.js tools/gita-order-scraper/tests/client.test.js tools/gita-order-scraper/tests/cli.test.js
git commit -m "feat: lock local Gita scraper runs"
```

## Task 4: Add the Gita Orders Launcher UI And Fallback Guide

**Files:**
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/pages/GitaOrderScrapeReport.vue`
- Modify: `frontend/src/pages/gitaOrderScrapeState.js`
- Test: `frontend/tests/gitaOrderScrapeState.test.js`

**Interfaces:**
- Consumes: `POST /marketplace/gita-order-scrapes/worker/wake` via `omnichannelService.wakeGitaOrderScraperWorker()`.
- Produces: `gitaOrderScraperLauncherMessage(result): { type: 'success'|'warning'|'error', text: string }` and component actions `wakeGitaOrderScraperWorker()` and bounded report polling.

- [ ] **Step 1: Write failing UI-state tests**

Add exact safe-copy expectations:

```js
assert.deepEqual(gitaOrderScraperLauncherMessage({ status: 'started' }), {
  type: 'success',
  text: 'Scraper Gita sedang dijalankan di PC ini.'
})
assert.equal(
  gitaOrderScraperLauncherMessage({ status: 'marketplace_busy', operation: 'stb_marketplace_sync' }).text,
  'Scraper belum dijalankan karena operasi marketplace lain masih aktif.'
)
```

Assert unknown details, tokens, and browser text do not appear in messages.

- [ ] **Step 2: Run the focused frontend test to verify RED**

Run: `npm test -- --test-name-pattern="launcher"`

Expected: FAIL because the launcher state helper is absent.

- [ ] **Step 3: Implement the frontend service and state helper**

Add:

```js
wakeGitaOrderScraperWorker() {
  return api.post('/gita-order-scrapes/worker/wake', {}, { skipAuthRedirect: true })
}
```

Add the state helper that maps only the four public statuses to Indonesian copy. Use generic wording for `marketplace_busy`; do not render `operation` from the response.

- [ ] **Step 4: Implement the page controls**

In `GitaOrderScrapeReport.vue`:

- add a `Jalankan Scraper PC` button in the header actions, disabled only while the request itself is pending;
- render an inline launcher guide with the exact manual fallback command:

```powershell
Set-Location 'C:\laragon\www\agnishopbjm-laravel'
npm run gita-order-scrape
```

- explain that it must be run once only and that login/CAPTCHA results are terminal and require a human review;
- on `started` or `already_running`, refresh page 1 immediately and poll `loadReport(1)` every 5 seconds for at most the configured lease duration or until the latest run’s `finishedAt` changes; clear the timer on unmount;
- on `marketplace_busy` or `manual_required`, show only the safe state message and do not poll indefinitely;
- preserve existing `Sync Semua` and per-line sync behavior exactly.

- [ ] **Step 5: Run the frontend test to verify GREEN**

Run: `npm test`

Expected: all frontend tests pass.

- [ ] **Step 6: Build the production frontend**

Run: `npm run build`

Expected: Vite exits `0` and emits the updated bundle in `frontend/dist`.

- [ ] **Step 7: Commit the UI source changes**

```powershell
git add frontend/src/services/index.js frontend/src/pages/GitaOrderScrapeReport.vue frontend/src/pages/gitaOrderScrapeState.js frontend/tests/gitaOrderScrapeState.test.js
git commit -m "feat: add Gita scraper launcher control"
```

## Task 5: Document, Publish, And Verify End To End

**Files:**
- Modify: `tools/gita-order-scraper/README.md`
- Modify: `backend/public/index.html`
- Create/Modify: `backend/public/assets/index-*.js`
- Create/Modify: `backend/public/assets/index-*.css`
- Test: existing Laravel, Node, and frontend suites

**Interfaces:**
- Consumes: successful Vite build artifacts and local Laravel host `http://agnishopbjm-laravel.test`.
- Produces: deployed `/marketplace/gita-orders` bundle containing the launcher button and fallback guide.

- [ ] **Step 1: Update operator documentation**

In `tools/gita-order-scraper/README.md`, document:

- use the Gita Orders button as the normal method;
- PowerShell fallback command;
- one-process lock behavior;
- `marketplace_busy` waits for the other operation to complete instead of killing it;
- `needs_login`/verification requires the human to sign into the existing Gita profile before starting a new run;
- no automatic stock synchronization is triggered.

Do not document actual token values or profile content.

- [ ] **Step 2: Run backend, worker, and frontend verification**

Run:

```powershell
backend\vendor\bin\phpunit
npm run test:gita-order-scraper
npm --prefix frontend test
npm --prefix frontend run build
```

Expected: all commands exit `0` with no failed tests.

- [ ] **Step 3: Publish the frontend bundle to Laragon**

Run:

```powershell
Copy-Item -Path frontend\dist\index.html -Destination backend\public\index.html -Force
Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets -Force
```

- [ ] **Step 4: Verify deployed assets and API behavior**

Run read-only checks:

```powershell
Invoke-WebRequest -UseBasicParsing http://agnishopbjm-laravel.test/marketplace/gita-orders
Invoke-WebRequest -UseBasicParsing http://agnishopbjm-laravel.test/api/gita-order-scrapes/latest
```

Verify the served JavaScript asset contains `Jalankan Scraper PC` and `npm run gita-order-scrape`.

If no active marketplace lease exists, call `POST /api/gita-order-scrapes/worker/wake` only once, confirm a single Node process/lock file, then allow it to reach a terminal result. If another marketplace operation is active, verify only `marketplace_busy` and do not bypass or terminate that operation.

- [ ] **Step 5: Inspect non-secret operational artifacts**

Check that at most one `node.exe` command line contains `tools/gita-order-scraper/src/cli.js`, and that the lock file is absent after a terminal CLI exit. Review only file names, exit status, and sanitized logs; never print environment files, tokens, cookies, or browser profile paths.

- [ ] **Step 6: Commit documentation and published assets**

```powershell
git add tools/gita-order-scraper/README.md backend/public/index.html backend/public/assets
git commit -m "docs: document automatic Gita scraper launcher"
```

## Plan Self-Review

- Every browser start is gated by the central marketplace lease and the local exclusive lock.
- Manual and automatic paths converge on the same CLI lease/lock handling.
- Public UI/API copy has no secret-bearing fields; only the worker bearer token accesses claim lifecycle endpoints.
- The plan preserves current read-only scraping and keeps stock synchronization user-triggered separately.
- Verification distinguishes safe blocked states from a successful collection and never starts a second scraper to test the guard.