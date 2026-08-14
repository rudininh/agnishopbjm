# Gitashopcollection Automatic Mass Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate the six current Shopee Mass Update XLSX files and upload them serially to the Gitashopcollection Seller Centre account, with durable audit records and mutual exclusion against STB marketplace work.

**Architecture:** Laravel owns job creation, file generation, audit records, API contracts, and STB lease checks. A separate local Node/Playwright daemon, using a persistent Gitashopcollection-only browser profile, claims one job and performs the Seller Centre interaction. The worker must report every state transition to Laravel; Laravel treats missing, malformed, or ambiguous Seller Centre evidence as a fail-closed terminal state.

**Tech Stack:** Laravel 11, PostgreSQL migrations, Laravel HTTP/Cache/DB facades, Vue 3, Vite, Node.js ESM, Playwright, Node test runner, PHPUnit 10.

## Global Constraints

- Target account is fixed to `Gitashopcollection`; no user-provided shop name, account key, URL, or profile path is accepted by API/UI requests.
- Process all six types serially: `basic-info`, `sales-info`, `media-info`, `shipping-info`, `dts-info`, and `republish-items`.
- Upload `republish-items` even when its generated data-row count is `0`.
- Never reuse `GITA_ORDER_SCRAPER_*`, its browser profile, its token, or its worker process.
- Worker default is visible (`headless=false`); login, OTP, CAPTCHA, or account verification stop the active job at `menunggu_verifikasi`.
- Do not log browser HTML, cookies, authorization headers, access tokens, local profile paths, raw Seller Centre responses, or credentials.
- Do not auto-retry an upload. A user retry creates a distinct job and a new audit trail.
- A file is uploaded only after the STB lease is held and immediately rechecked. Release the lease on all terminal paths.
- Do not commit, push, or discard the existing unrelated working-tree changes.

---

## File Structure

- Create `backend/config/shopee_mass_upload.php` — fixed Gitashop settings, worker authentication secret, timeout, and remote-STB control settings.
- Create `backend/database/migrations/2026_08_12_000001_create_shopee_mass_upload_tables.php` — job, file-audit, worker heartbeat, and one-row-per-account runtime lock tables.
- Create `backend/database/migrations/2026_08_12_000002_add_marketplace_operation_lease_to_sync_runtime_statuses.php` — durable STB/upload operation lease fields.
- Create `backend/app/Services/ShopeeGitaMassUpdateGenerator.php` — extract XLSX generation from the import controller and return auditable file metadata.
- Create `backend/app/Services/MarketplaceOperationLeaseService.php` — acquire, renew, inspect, and release a time-bounded mutation lease.
- Create `backend/app/Services/ShopeeMassUploadStbGuard.php` — obtain/release the local or remote STB lease and fail closed on unavailable remote status/control endpoints.
- Create `backend/app/Services/ShopeeMassUploadService.php` — create/claim jobs, persist state transitions, produce downloads, and clear locks.
- Create `backend/app/Http/Controllers/ShopeeMassUploadController.php` — user-facing status/start/history APIs plus token-protected worker APIs.
- Create `backend/app/Http/Middleware/EnsureShopeeMassUploadWorkerToken.php` — constant-time validation of the dedicated worker bearer token.
- Modify `backend/app/Http/Controllers/MarketplaceImportController.php` — delegate existing Shopee Gita download generation to `ShopeeGitaMassUpdateGenerator`; preserve URLs and response headers.
- Modify `backend/app/Services/StbRuntimeService.php` and `backend/app/Services/StbSyncWorkerService.php` — expose and honor the marketplace operation lease around order/marketplace work.
- Modify `backend/app/Http/Controllers/SyncRuntimeController.php` and `backend/routes/api.php` — publish protected lease endpoints and Mass Upload APIs.
- Create `tools/gitashop-mass-upload-worker/src/{cli.js,config.js,client.js,shopee-upload.js}` — persistent Gitashop-only Playwright worker.
- Create `tools/gitashop-mass-upload-worker/tests/{config.test.js,client.test.js,cli.test.js,shopee-upload.test.js}` — offline worker contract tests.
- Modify `package.json` — add `gitashop-mass-upload-worker` and one-shot diagnostic scripts without changing Gita scripts.
- Create `frontend/src/pages/gitashopMassUploadState.js` and `frontend/tests/gitashopMassUploadState.test.js` — pure status/formatting state.
- Modify `frontend/src/services/index.js` and `frontend/src/pages/ImportMarketplace.vue` — start control, live status, per-file audit, and history panel.
- Create `docs/gitashop-mass-upload-worker.md` — worker setup, first-login, scheduler/daemon operation, recovery, and security rules.
- Create/modify focused backend feature tests under `backend/tests/Feature/` and service tests under `backend/tests/Unit/` following the project’s existing `RefreshDatabase` pattern.

## Task 1: Add Durable Audit and Worker Configuration

**Files:**
- Create: `backend/database/migrations/2026_08_12_000001_create_shopee_mass_upload_tables.php`
- Create: `backend/config/shopee_mass_upload.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/ShopeeMassUploadControllerTest.php`

**Interfaces:**
- Produces `shopee_mass_upload_jobs`, `shopee_mass_upload_files`, and `shopee_mass_upload_runtimes` database tables.
- Produces config keys `shopee_mass_upload.account_key`, `expected_shop_name`, `worker_token`, `worker_heartbeat_seconds`, `stb_control_url`, and `stb_control_token`.

- [ ] **Step 1: Write failing migration/config assertions**

Add a test bootstrapping the tables with `RefreshDatabase`, then assert configuration defaults are fixed and job rows can store the complete audit contract:

```php
public function test_mass_upload_configuration_is_locked_to_gitashopcollection(): void
{
    $this->assertSame('shopee-gitacollectionbjm', config('shopee_mass_upload.account_key'));
    $this->assertSame('Gitashopcollection', config('shopee_mass_upload.expected_shop_name'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeMassUploadControllerTest.php --filter=configuration`

Expected: FAIL because the config and/or tables do not exist.

- [ ] **Step 3: Create the schema and config**

Create:

```text
shopee_mass_upload_jobs
  id, account_key, expected_shop_name, status, message,
  requested_at, started_at, finished_at, worker_last_seen_at, timestamps

shopee_mass_upload_files
  id, job_id FK, sequence, file_type, filename, storage_path,
  row_count, sha256, status, shopee_status, shopee_processed_count,
  created_at_worker, uploaded_at, completed_at, error_code, message, timestamps
  UNIQUE(job_id, file_type), UNIQUE(job_id, sequence)

shopee_mass_upload_runtimes
  account_key UNIQUE, active_job_id nullable, worker_last_seen_at,
  worker_name nullable, timestamps
```

Use `string` statuses, nullable timestamps, database indexes on `(account_key, status, requested_at)` and `(job_id, sequence)`. Store only generated relative storage paths, never a browser profile path. Set the config account/name as literal defaults; read only timeout/token/control values from environment. Add blank `GITASHOP_MASS_UPLOAD_*` variables to `.env.example`, never a real secret.

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeMassUploadControllerTest.php --filter=configuration`

Expected: PASS.

## Task 2: Extract Deterministic Shopee Gita File Generation

**Files:**
- Create: `backend/app/Services/ShopeeGitaMassUpdateGenerator.php`
- Modify: `backend/app/Http/Controllers/MarketplaceImportController.php`
- Test: `backend/tests/Feature/ShopeeGitaMassUpdateGeneratorTest.php`

**Interfaces:**
- `ShopeeGitaMassUpdateGenerator::generate(string $relativeDirectory): array` returns six ordered metadata arrays: `file_type`, `filename`, `storage_path`, `row_count`, and lowercase SHA-256 `sha256`.
- `ShopeeGitaMassUpdateGenerator::definitions(): array` returns the canonical six-type ordering and filenames.
- Existing download endpoints retain their current URL and downloaded file contents.

- [ ] **Step 1: Write failing generator tests**

Use small copied templates/seeded rows and assert:

```php
$files = app(ShopeeGitaMassUpdateGenerator::class)->generate('import-marketplace/jobs/test-1');

$this->assertSame([
    'basic-info', 'sales-info', 'media-info',
    'shipping-info', 'dts-info', 'republish-items',
], array_column($files, 'file_type'));
$this->assertSame(0, $files[5]['row_count']);
$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $files[0]['sha256']);
```

Also preserve regression coverage that Sales Info updates SKU/stock but not price, Media accepts only `https://cf.shopee.co.id/`, and Republish replaces all data rows with none.

- [ ] **Step 2: Run generator tests to verify failure**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeGitaMassUpdateGeneratorTest.php`

Expected: FAIL because the service is absent.

- [ ] **Step 3: Move generation responsibility into the service**

Move `shopeeGitaTemplates`, all six fill methods, workbook XML helpers, source-product queries, media sanitization, and row-count calculation from `MarketplaceImportController` into the generator. Use the existing templates in `storage/app/import-marketplace/shopee-gita`; do not alter their hidden metadata rows or localized headers. Have the controller call the new service for ZIP and individual downloads, preserving `deleteFileAfterSend(true)` behavior for download-only temporary directories.

For job directories, leave generated files in `storage/app/import-marketplace/generated/jobs/<job-id>` until a future explicit retention policy; calculate SHA-256 with `hash_file('sha256', storage_path('app/'.$relativePath))` after writing.

- [ ] **Step 4: Run focused tests and existing download regression**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeGitaMassUpdateGeneratorTest.php backend/tests/Feature/MarketplaceImportControllerTest.php`

Expected: PASS. If the controller test does not yet exist, add it first and prove current download endpoints still return XLSX/ZIP responses.

## Task 3: Implement Marketplace Operation Leases Shared With STB

**Files:**
- Create: `backend/database/migrations/2026_08_12_000002_add_marketplace_operation_lease_to_sync_runtime_statuses.php`
- Create: `backend/app/Services/MarketplaceOperationLeaseService.php`
- Modify: `backend/app/Services/StbRuntimeService.php`
- Modify: `backend/app/Services/StbSyncWorkerService.php`
- Modify: `backend/app/Http/Controllers/SyncRuntimeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/MarketplaceOperationLeaseTest.php`

**Interfaces:**
- `MarketplaceOperationLeaseService::acquire(string $operation, int $seconds): array` returns `acquired`, `token`, `operation`, and `locked_until_at`.
- `renew(string $token, int $seconds): bool`, `release(string $token): void`, and `status(): array` are safe for repeated calls.
- `GET /api/runtime/stb-status` returns a sanitized `marketplace_operation` object.
- `POST /api/runtime/marketplace-operation/acquire|renew|release` requires the dedicated control token and never exposes a lease token in a public status response.

- [ ] **Step 1: Write failing mutual-exclusion tests**

Test two contenders against the same runtime row:

```php
$upload = $leases->acquire('gitashop_mass_upload', 120);
$stb = $leases->acquire('stb_marketplace_sync', 120);

$this->assertTrue($upload['acquired']);
$this->assertFalse($stb['acquired']);
$this->assertSame('gitashop_mass_upload', $stb['operation']);
```

Also test expired leases can be reclaimed, invalid control tokens are rejected, and `StbSyncWorkerService::syncOrders()` plus `syncMarketplaceLite()` skip safely while a Gitashop upload lease is active.

- [ ] **Step 2: Run to verify failures**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceOperationLeaseTest.php`

Expected: FAIL because no lease service/endpoints exist.

- [ ] **Step 3: Add lease columns, service, and STB integration**

Add nullable `marketplace_operation`, `marketplace_operation_token`, and `marketplace_operation_locked_until_at` fields to `sync_runtime_statuses`. Implement acquisition inside a database transaction using `lockForUpdate()` on the permanent runtime row; reclaim only expired leases. Return a generic busy reason without revealing another actor’s token.

Wrap the actual work in `StbSyncWorkerService::syncOrders()` and `syncMarketplaceLite()` with acquire/release in `try/finally`. When busy, record a `skipped` sync log indicating a protected marketplace operation is active. Add the operation name and expiry (not token) to `StbRuntimeService::status()`. Add token-protected control endpoints for a remote STB; use a distinct `GITASHOP_MASS_UPLOAD_STB_CONTROL_TOKEN`, never the mapping or Gita ingestion token.

- [ ] **Step 4: Run focused suite**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceOperationLeaseTest.php`

Expected: PASS, including lease release after an exception.

## Task 4: Implement Backend Job Lifecycle and Worker Protocol

**Files:**
- Create: `backend/app/Services/ShopeeMassUploadStbGuard.php`
- Create: `backend/app/Services/ShopeeMassUploadService.php`
- Create: `backend/app/Http/Controllers/ShopeeMassUploadController.php`
- Create: `backend/app/Http/Middleware/EnsureShopeeMassUploadWorkerToken.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ShopeeMassUploadControllerTest.php`

**Interfaces:**
- User endpoints:
  - `POST /api/marketplace/import/shopee-gita/mass-upload/jobs`
  - `GET /api/marketplace/import/shopee-gita/mass-upload/jobs/current`
  - `GET /api/marketplace/import/shopee-gita/mass-upload/jobs?per_page=20`
- Worker endpoints protected only by `EnsureShopeeMassUploadWorkerToken`:
  - `POST /api/internal/shopee-gita-mass-upload/heartbeat`
  - `POST /api/internal/shopee-gita-mass-upload/claim`
  - `GET /api/internal/shopee-gita-mass-upload/jobs/{job}/files/{file}/download`
  - `POST /api/internal/shopee-gita-mass-upload/jobs/{job}/files/{file}/event`
  - `POST /api/internal/shopee-gita-mass-upload/jobs/{job}/terminal`
- `ShopeeMassUploadService` accepts only the fixed account and allowed transitions.

- [ ] **Step 1: Write failing API lifecycle tests**

Cover each requirement before implementation:

```php
$this->postJson('/api/marketplace/import/shopee-gita/mass-upload/jobs')
    ->assertCreated()
    ->assertJsonPath('data.account_key', 'shopee-gitacollectionbjm')
    ->assertJsonPath('data.status', 'menunggu_stb');

$this->postJson('/api/marketplace/import/shopee-gita/mass-upload/jobs')
    ->assertConflict();
```

Add tests for worker token rejection, heartbeat, oldest-job claim, six generated file records in canonical order, binary download limited to the claimed job, valid transitions only, release of active runtime lock at terminal status, and no automatic retry after `gagal`/`menunggu_verifikasi`.

- [ ] **Step 2: Run tests to verify failures**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeMassUploadControllerTest.php`

Expected: FAIL because the routes/service do not exist.

- [ ] **Step 3: Implement the lifecycle**

Within one database transaction, lock the `shopee_mass_upload_runtimes` row and reject creation if its active job is nonterminal. Create an initial `menunggu_stb` job. On each worker claim and before each file event, `ShopeeMassUploadStbGuard` must acquire/renew the local lease or call the remote STB control API when configured. If STB cannot be contacted, return `dibatalkan_aman` before any file is downloaded/uploaded.

The claim operation must: verify worker heartbeat; create the six files through `ShopeeGitaMassUpdateGenerator`; persist row count/hash/path; set one file to `dibuat`; and return only job/file IDs, filenames, expected shop name, and upload page URL. Do not return cookies, profile paths, secrets, or filesystem locations. File download streams one generated XLSX and sets `Cache-Control: no-store`.

Allow only this per-file transition sequence: `menunggu -> dibuat -> diunggah -> memproses -> selesai`; terminal alternatives are `gagal` and `menunggu_verifikasi`. When a file is terminal-success, mark the next sequence as `dibuat`; when any file is terminal-failure/verification, set the job terminal, release the STB lease, and clear `active_job_id` in `finally`. Mark `selesai` only after all six files report Shopee `Selesai`; `republish-items` must explicitly report processed count `0`.

- [ ] **Step 4: Run lifecycle tests**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/ShopeeMassUploadControllerTest.php backend/tests/Feature/MarketplaceOperationLeaseTest.php`

Expected: PASS.

## Task 5: Build the Dedicated Gitashop Playwright Worker

**Files:**
- Create: `tools/gitashop-mass-upload-worker/src/config.js`
- Create: `tools/gitashop-mass-upload-worker/src/client.js`
- Create: `tools/gitashop-mass-upload-worker/src/shopee-upload.js`
- Create: `tools/gitashop-mass-upload-worker/src/cli.js`
- Create: `tools/gitashop-mass-upload-worker/tests/config.test.js`
- Create: `tools/gitashop-mass-upload-worker/tests/client.test.js`
- Create: `tools/gitashop-mass-upload-worker/tests/shopee-upload.test.js`
- Create: `tools/gitashop-mass-upload-worker/tests/cli.test.js`
- Modify: `package.json`
- Test: `tools/gitashop-mass-upload-worker/tests/*.test.js`

**Interfaces:**
- `loadMassUploadWorkerConfig(env)` reads only `GITASHOP_MASS_UPLOAD_*`, with a default profile path of `tools/gitashop-mass-upload-worker/.profile`.
- `runMassUploadWorker(config, dependencies)` heartbeats, claims at most one job at a time, and returns a sanitized result object.
- `validateActiveShop(page, expectedName)`, `uploadMassUpdateFile(page, filePath, expectedFileType)`, and `waitForShopeeProcessing(page, expectedFilename)` use dependency injection for tests.
- CLI supports `--once` (one claim only) and default polling. It never launches more than one persistent browser context.

- [ ] **Step 1: Read-only Seller Centre selector discovery**

With the new Gitashop profile directory only, open `https://seller.shopee.co.id/portal/product-mass/mass-update/upload` visibly. Do not call `setInputFiles`, click upload/submit, or alter Seller Centre data. Capture only the stable accessible labels/test IDs for: current shop identity, file-type selection, file input, submit action, processing history row, filename, processed count, completed status, login, OTP, CAPTCHA, and verification screens. Store selector constants and an offline DOM fixture without cookies or account/order data.

- [ ] **Step 2: Write offline failing worker tests**

Test these exact cases with fake page/client dependencies:

```js
assert.equal(normalizeShopName('Gita Shop Collection'), 'gitashopcollection')
assert.throws(() => assertExpectedShop('Akun Lain', 'Gitashopcollection'), /expected shop/i)
assert.equal(classifySellerCentreState({ login: true }), 'needs_login')
assert.equal(classifySellerCentreState({ captcha: true }), 'needs_verification')
```

Add a serial-run test proving the worker posts `menunggu_verifikasi` on OTP/CAPTCHA, never claims/starts a later file after one failure, and sends `Selesai` with `processed_count: 0` for the republish file.

- [ ] **Step 3: Run worker tests to verify failures**

Run: `node --test tools/gitashop-mass-upload-worker/tests/*.test.js`

Expected: FAIL because the worker modules are absent.

- [ ] **Step 4: Implement worker and safe browser automation**

Use `chromium.launchPersistentContext(config.profileDir, { headless: config.headless })`; reject profiles that resolve to the Gita worker profile. Read the dedicated token from `backend/.env` using the same quoted-value normalization pattern as the Gita worker, but only for `GITASHOP_MASS_UPLOAD_WORKER_TOKEN`.

For each claimed file: download it to a worker-private temporary directory; call backend preflight/lease renewal; navigate to the fixed upload URL; verify the selected store normalized name equals `gitashopcollection`; select the matching type; attach only the exact generated filename; submit; and wait for an unambiguous Seller Centre history row with the expected filename and `Selesai`. Report `diunggah`, `memproses`, then `selesai`. If page state is login/OTP/CAPTCHA/verification, account identity is absent/mismatched, selector evidence is missing, upload is rejected, completion times out, or processing count differs from expected, report a sanitized terminal event and close the context.

Do not scrape product/order content. Do not issue an upload action in automated tests; unit tests must use fake pages only.

- [ ] **Step 5: Run worker test suite**

Run: `node --test tools/gitashop-mass-upload-worker/tests/*.test.js`

Expected: PASS. Then run the new `npm run gitashop-mass-upload-worker -- --once` only with no queued job to verify heartbeat/idle behavior; do not create a real job during this check.

## Task 6: Add Import Page Upload Controls and Audit History

**Files:**
- Create: `frontend/src/pages/gitashopMassUploadState.js`
- Create: `frontend/tests/gitashopMassUploadState.test.js`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/pages/ImportMarketplace.vue`

**Interfaces:**
- `toMassUploadViewModel(job)` returns display-safe job/file status, WITA timestamp, row count, and sanitized message.
- Services add `startShopeeGitaMassUpload()`, `currentShopeeGitaMassUpload()`, and `listShopeeGitaMassUploads(params)`.
- UI submits one start request, polls only while current job is nonterminal, and cannot offer a retry action for the same job.

- [ ] **Step 1: Write failing pure-state tests**

Cover WITA formatting and visible copy for all terminal states, especially `menunggu_verifikasi`, `dibatalkan_aman`, and `selesai_dengan_gagal`. Assert that a zero row Republish file displays `0 baris` and a successful file shows Shopee processed `0`, not “belum diproses.”

- [ ] **Step 2: Run frontend state test to verify failure**

Run: `npm --prefix frontend test -- gitashopMassUploadState.test.js`

Expected: FAIL because the module is absent.

- [ ] **Step 3: Implement UI state and panel**

Add a distinct `Upload Otomatis Gitashopcollection` panel above the existing download table. It shows fixed target account, current job status, STB wait/verification messages, active file, all six audit rows (filename, hash prefix, data row count, internal status, Seller Centre status/count, WITA timestamps), and the latest 20 jobs. Disable the start button while a nonterminal current job exists; on `409`, refresh current status instead of optimistically creating another job.

Keep existing individual ZIP/XLSX downloads intact. Do not expose worker token, profile location, raw error text, or a user-entered target shop field. Poll every 5 seconds only while active and stop/unmount cleanly.

- [ ] **Step 4: Run frontend tests and production build**

Run: `npm --prefix frontend test`

Run: `npm --prefix frontend run build`

Expected: all frontend tests PASS and Vite exits `0`.

## Task 7: Document Operation, Run Full Verification, and Publish Deliberately

**Files:**
- Create: `docs/gitashop-mass-upload-worker.md`
- Modify: `backend/.env.example`
- Modify: `package.json`
- Modify only generated deployment assets if the user explicitly asks to publish the frontend.

**Interfaces:**
- Documentation names exact local commands, required environment variable names (without values), one-time visible login, and safe recovery actions.

- [ ] **Step 1: Write operations documentation**

Document: installing root Node dependencies, starting the daemon (`npm run gitashop-mass-upload-worker`), one-time visible Seller Centre login in the dedicated profile, Windows Task Scheduler/long-running process setup, how to pause it, expected job statuses, where to view audit history, and recovery after `menunggu_verifikasi`/`dibatalkan_aman`. State clearly that a new job is required for retry and that the worker must never be pointed at the Gita profile.

- [ ] **Step 2: Run complete backend and worker verification**

Run: `backend/vendor/bin/phpunit`

Run: `node --test tools/gita-order-scraper/tests/*.test.js`

Run: `node --test tools/gitashop-mass-upload-worker/tests/*.test.js`

Expected: every command exits `0`; do not fix unrelated existing failures.

- [ ] **Step 3: Verify the requirement checklist**

Confirm from tests and code review:

```text
[ ] Gitashopcollection is immutable target.
[ ] Six files are generated fresh and uploaded serially.
[ ] Republish zero-row success is checked explicitly.
[ ] Job/worker locks prevent duplication.
[ ] STB and mass upload use the same lease contract.
[ ] Login/OTP/CAPTCHA/mismatched account fail closed.
[ ] No retry reuses an existing job.
[ ] Audit records avoid secrets and raw browser data.
[ ] Existing Gita worker remains read-only and isolated.
```

- [ ] **Step 4: Verify deployment only when requested**

If the user requests publishing, run the existing frontend build/copy flow, verify the updated local route/assets over HTTP, and separately verify that no mass-upload job is queued before any live Seller Centre run. Do not initiate an actual production upload as part of code verification.
