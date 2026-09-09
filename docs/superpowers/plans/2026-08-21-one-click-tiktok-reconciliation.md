# One-Click TikTok Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide one review-first action that creates safe Shopee-to-TikTok product and variant drafts, then submits only user-confirmed and still-current items.

**Architecture:** A `TiktokReconciliationService` builds, persists, reloads, and revision-locks a redacted preview from local Shopee, TikTok, and stock mapping caches. Two `OmnichannelController` actions expose preview and submit routes; the controller owns TikTok mutation/verification because its established helpers are private, while the service owns persisted run state and audit outcomes. A dedicated Vue review page starts the preview from Stock Anomalies and allows one confirmation only for safe items.

**Tech Stack:** PHP 8, Laravel, PostgreSQL and SQLite test database, PHPUnit, Vue 3, Vue Router, Axios, Vite, TikTok Shop Product API 202509.

## Global Constraints

- Preview is read-only: it must not call a TikTok write endpoint, upload an image, modify a SKU mapping, or alter `stock_master`.
- Never auto-resolve a `tiktok_sku_conflict`; do not create a SKU, mapping, or stock push for its seller SKU.
- New products require a user-confirmed TikTok category and all required TikTok attributes; do not infer or invent them.
- Submit must return HTTP 409 with `status: stale_revision` if its revision does not still match the stored preview or the current eligible cache rows.
- Persist only redacted audit payloads: remove `access_token`, `app_secret`, `sign`, `shop_cipher`, and `authorization` recursively before storage or response.
- Reuse existing TikTok partial-edit, generated-payload, image upload, cache, and verification conventions; do not duplicate signing code or expose credentials to Vue.
- Keep existing bulk-variant and manual-mapping flows unchanged.
- Do not add packages. Build frontend with `npm run build` and publish `frontend/dist` assets to `backend/public` after successful tests.

---

### Task 1: Persisted Reconciliation Run Schema

**Files:**
- Create: `backend/database/migrations/2026_08_21_000002_create_tiktok_reconciliation_runs_tables.php`
- Test: `backend/tests/Feature/TiktokReconciliationPersistenceTest.php`

**Interfaces:**
- Produces table `tiktok_reconciliation_runs` with `id` UUID primary key, `revision` string(64), `status` string(32), `summary` JSON, `submitted_at` nullable timestamp, `completed_at` nullable timestamp, and timestamps.
- Produces table `tiktok_reconciliation_run_items` with an id, `run_id` UUID FK, nullable `stock_master_id`, `item_key` string(191), `action_type` string(32), `status` string(32), `source_fingerprint` string(64), nullable `target_product_id`, nullable `target_sku_id`, nullable `block_reason`, nullable `payload`, nullable `result`, and timestamps.
- Enforces `unique(run_id, item_key)` and indexes `status`, `action_type`, and `stock_master_id` for deterministic retries and audit lookup.

- [ ] **Step 1: Write the failing migration persistence test**

```php
public function test_reconciliation_migration_persists_run_and_item_with_unique_item_key(): void
{
    $this->assertTrue(Schema::hasTable('tiktok_reconciliation_runs'));
    $this->assertTrue(Schema::hasTable('tiktok_reconciliation_run_items'));

    DB::table('tiktok_reconciliation_runs')->insert([
        'id' => '9df1f8af-940c-48d9-a2d8-81f3e264f350',
        'revision' => str_repeat('a', 64),
        'status' => 'ready_for_review',
        'summary' => json_encode(['new_products' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('tiktok_reconciliation_run_items')->insert([
        'run_id' => '9df1f8af-940c-48d9-a2d8-81f3e264f350',
        'item_key' => 'new-product:42',
        'action_type' => 'new_product',
        'status' => 'ready',
        'source_fingerprint' => str_repeat('b', 64),
        'payload' => json_encode(['seller_sku' => 'INT-42-RED']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseCount('tiktok_reconciliation_run_items', 1);
}
```

- [ ] **Step 2: Verify RED**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter test_reconciliation_migration_persists_run_and_item_with_unique_item_key`

Expected: FAIL because neither reconciliation table exists.

- [ ] **Step 3: Add idempotent Laravel migration**

Create both tables through `Schema::create`, use `uuid('id')->primary()` for runs and `foreignUuid('run_id')->constrained('tiktok_reconciliation_runs')->cascadeOnDelete()` for items. Use JSON columns for structured data, nullable foreign stock-master id without a database FK because existing imports may remove source rows, and the exact unique/indexes described above. The `down()` method drops items before runs.

- [ ] **Step 4: Verify GREEN**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter TiktokReconciliationPersistenceTest`

Expected: PASS with a persisted run and one persisted item.

### Task 2: Build Read-Only Safe Preview Service

**Files:**
- Create: `backend/app/Services/TiktokReconciliationService.php`
- Modify: `backend/app/Services/MarketplaceSyncService.php:184`
- Test: `backend/tests/Unit/Services/TiktokReconciliationServiceTest.php`

**Interfaces:**
- Produces `TiktokReconciliationService::createPreview(): array` returning `status`, `run_id`, `revision`, `summary`, `new_products`, `variant_additions`, and `conflicts`.
- Produces `TiktokReconciliationService::loadRun(string $runId): array` returning the persisted public review shape without secrets.
- Produces `TiktokReconciliationService::claimCurrentRun(string $runId, string $revision): array` and `TiktokReconciliationService::recordItemResult(string $runId, string $itemKey, array $result): void` for controller submission orchestration.
- Each item has `item_key`, `action_type`, `status`, `stock_master_ids`, `source_fingerprint`, `product`, `variants`, `target_product_id`, and nullable `block_reason`.
- Consumes the same active mapping/cache truth used by `MarketplaceSyncService::stockAnomalies()`; `tiktok_sku_conflict` always becomes `action_type: conflict`, `status: blocked`.

- [ ] **Step 1: Write failing preview classification and no-write tests**

```php
public function test_preview_groups_unmapped_shopee_product_as_new_product_without_tiktok_write(): void
{
    $this->seedShopeeOnlyProductWithTwoVariants('item-100');
    Http::fake();

    $preview = app(TiktokReconciliationService::class)->createPreview();

    $this->assertSame('ready_for_review', $preview['status']);
    $this->assertSame(1, $preview['summary']['new_products']);
    $this->assertCount(1, $preview['new_products']);
    $this->assertSame('new_product:item-100', $preview['new_products'][0]['item_key']);
    Http::assertNothingSent();
    $this->assertDatabaseHas('tiktok_reconciliation_runs', ['id' => $preview['run_id']]);
}

public function test_preview_blocks_ambiguous_seller_sku_instead_of_drafting_or_mapping_it(): void
{
    $this->seedAmbiguousShopeeAndTiktokSellerSku('INT-CONFLICT');

    $preview = app(TiktokReconciliationService::class)->createPreview();

    $this->assertSame(1, $preview['summary']['conflicts']);
    $this->assertSame('conflict', $preview['conflicts'][0]['action_type']);
    $this->assertSame('tiktok_sku_conflict', $preview['conflicts'][0]['block_reason']);
    $this->assertDatabaseMissing('sku_mappings', ['seller_sku' => 'INT-CONFLICT']);
}
```

- [ ] **Step 2: Verify RED**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter TiktokReconciliationServiceTest`

Expected: FAIL because `TiktokReconciliationService` does not exist.

- [ ] **Step 3: Implement deterministic preview construction and persistence**

Query active `stock_master`, `sku_mappings`, `shopee_product`, `shopee_product_model`, variant/product image caches, and active `tiktok_products`. Group missing TikTok target products by Shopee item id as `new_product`; group safe single-target missing seller SKUs by TikTok product id as `variant_addition`; classify duplicate or differently named seller-SKU fallbacks as blocked conflicts. Validate draft minimums (internal SKU unique, positive price and stock, non-empty source image) into `blocked` items with an explicit reason. Canonically sort all data, hash the full unredacted source identity to generate each fingerprint and hash the public preview source fingerprints to generate `revision`.

Persist a new UUID run and one item row per product group or conflict. Store only `redactPayload()` output in JSON, where recursion removes the five secret keys in the global constraints. Return the same redacted item shape and summary after persistence. Do not call HTTP, TikTok helpers, mutation helpers, mapping writes, or stock sync code.

- [ ] **Step 4: Add stale-input and redaction tests**

```php
public function test_preview_revision_changes_when_source_variant_changes_and_never_persists_secrets(): void
{
    $this->seedShopeeOnlyProductWithTwoVariants('item-101');
    $first = app(TiktokReconciliationService::class)->createPreview();
    DB::table('stock_master')->where('internal_sku', 'INT-101-RED')->update(['stock_qty' => 8]);
    $second = app(TiktokReconciliationService::class)->createPreview();

    $payload = (string) DB::table('tiktok_reconciliation_run_items')->where('run_id', $first['run_id'])->value('payload');
    $this->assertNotSame($first['revision'], $second['revision']);
    $this->assertStringNotContainsString('access_token', $payload);
    $this->assertStringNotContainsString('app_secret', $payload);
    $this->assertStringNotContainsString('sign', $payload);
}
```

- [ ] **Step 5: Verify GREEN**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter TiktokReconciliationServiceTest`

Expected: PASS; preview is persisted, deterministic, redacted, and sends no HTTP requests.

### Task 3: Expose Preview and Guarded Submit Endpoints

**Files:**
- Modify: `backend/routes/api.php:52-60`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:5243, 5458, 6030, 6687, 10882`
- Modify: `backend/app/Services/TiktokReconciliationService.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Test: `backend/tests/Feature/TiktokReconciliationSubmitTest.php`

**Interfaces:**
- Adds `POST /api/tiktok/reconciliation-runs/preview` handled by `OmnichannelController::tiktokReconciliationPreview(Request $request): JsonResponse`.
- Adds `POST /api/tiktok/reconciliation-runs/{runId}/submit` handled by `OmnichannelController::submitTiktokReconciliationRun(Request $request, string $runId): JsonResponse`.
- Submit input is `{ revision: string, selected_new_product_keys: string[], selected_variant_product_ids: string[], product_details: Record<string, { category_id: string, attributes: array, title?: string, description?: string }> }`.
- Produces per item `{ item_key, action_type, status, message, product_id?, variants: [] }` where status is `updated`, `submitted_unverified`, `skipped`, or `failed`.

- [ ] **Step 1: Write failing route and validation tests**

```php
public function test_preview_route_returns_persisted_review_without_tiktok_mutation(): void
{
    $this->seedShopeeOnlyProductWithTwoVariants('item-200');
    Http::fake();

    $response = $this->postJson('/api/tiktok/reconciliation-runs/preview');

    $response->assertCreated()->assertJsonPath('status', 'ready_for_review')
        ->assertJsonStructure(['run_id', 'revision', 'summary', 'new_products', 'variant_additions', 'conflicts']);
    Http::assertNothingSent();
}

public function test_submit_rejects_unknown_or_stale_revision_before_any_tiktok_call(): void
{
    $preview = $this->createReconciliationPreviewFixture();
    Http::fake();

    $response = $this->postJson(/api/tiktok/reconciliation-runs/{$preview['run_id']}/submit, [
        'revision' => str_repeat('0', 64),
        'selected_new_product_keys' => [$preview['new_products'][0]['item_key']],
        'selected_variant_product_ids' => [],
        'product_details' => [],
    ]);

    $response->assertStatus(409)->assertJsonPath('status', 'stale_revision');
    Http::assertNothingSent();
}
```

- [ ] **Step 2: Verify RED**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter (preview_route_returns|submit_rejects_unknown_or_stale)`

Expected: FAIL with missing routes/controller methods.

- [ ] **Step 3: Implement thin controller actions and service submit validation**

Inject `TiktokReconciliationService` into `OmnichannelController`. The preview action calls `createPreview()` and returns 201. The submit action validates the precise request shape, calls `claimCurrentRun()` to obtain a row lock plus rechecked fingerprints, and rejects any non-`ready_for_review` run or revision/fingerprint mismatch with the exact 409 response.

The controller rejects selections that are not persisted safe items and ignores blocked conflict keys even if forged in the request. It requires non-empty `category_id` and non-empty attributes for every selected new product before token refresh, image upload, or TikTok mutation, returning HTTP 422 field errors keyed by `product_details.<item_key>.category_id` and `.attributes`.

- [ ] **Step 4: Write failing mutation orchestration tests**

```php
public function test_submit_skips_conflict_and_updates_mapping_only_after_verified_variant_exists(): void
{
    $preview = $this->createSafeVariantAdditionAndConflictPreview();
    $controller = $this->partialMock(OmnichannelController::class, function ($mock): void {
        $mock->shouldReceive('submitPreparedBulkTiktokVariantBatch')->once()->andReturn(['ok' => true]);
        $mock->shouldReceive('verifyBulkTiktokVariantGroup')->once()->andReturn([
            'INT-SAFE' => ['status' => 'updated', 'sku_id' => 'tt-safe'],
        ]);
    });

    $response = $this->postJson(/api/tiktok/reconciliation-runs/{$preview['run_id']}/submit, $this->safeSubmitPayload($preview));

    $response->assertOk()->assertJsonPath('summary.updated', 1)->assertJsonPath('summary.skipped', 1);
    $this->assertDatabaseHas('sku_mappings', ['seller_sku' => 'INT-SAFE', 'tiktok_sku_id' => 'tt-safe']);
    $this->assertDatabaseMissing('sku_mappings', ['seller_sku' => 'INT-CONFLICT']);
}
```

- [ ] **Step 5: Implement submit orchestration with existing TikTok helpers**

For safe existing-product groups, refresh the target detail, upload each required image through `uploadTiktokProductImage()`, use `submitPreparedBulkTiktokVariantBatch()`/`submitTiktokVariantMutation()` conventions, then call `verifyBulkTiktokVariantGroup()` once per product. For a new product, construct the existing generated-payload-compatible body from the reviewed category/attributes plus the stored Shopee title, description, images, dimensions, price, stock, and seller SKUs; normalize defaults and images through the established generated payload helpers, submit through the existing product creation convention, and refresh/cache the returned product before verification.

For every verified seller SKU, update `sku_mappings` and `stock_master` using the same columns as `saveSkuMapping()`, then invoke the established stock synchronization path. Persist redacted request/result data and final status for every run item. A TikTok accepted response without refreshed SKU presence becomes `submitted_unverified`, never a mapping. Catch a failure per product group so independent groups continue; mark remaining safe but unselected items as `skipped`, and mark conflict items as `skipped` with their original block reason.

- [ ] **Step 6: Verify GREEN**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter (TiktokReconciliationSubmitTest|preview_route_returns|submit_rejects_unknown_or_stale|submit_skips_conflict)`

Expected: PASS; stale and invalid submits make zero HTTP calls, conflict stays unmapped, and verified safe rows receive mappings.

### Task 4: Add Review-First Vue Flow

**Files:**
- Modify: `frontend/src/services/index.js:160-166`
- Modify: `frontend/src/router/index.js:1-125`
- Modify: `frontend/src/pages/StockAnomalies.vue:1-287`
- Create: `frontend/src/pages/TiktokReconciliationReview.vue`
- Create: `frontend/src/pages/tiktokReconciliationState.js`
- Create: `frontend/tests/tiktokReconciliationState.test.js`

**Interfaces:**
- Adds `omnichannelService.createTiktokReconciliationPreview()` and `omnichannelService.submitTiktokReconciliationRun(runId, data)`.
- Adds route `/marketplace/tiktok-reconciliation-review/:runId` named `marketplace-tiktok-reconciliation-review`.
- Produces pure helpers `initialReconciliationSelection(preview)`, `buildReconciliationSubmitPayload(preview, selection, details)`, and `reconciliationSubmitDisabled(preview, selection, details)`.
- `StockAnomalies.vue` calls preview then routes only after backend returns a `run_id`; it never submits mutations itself.

- [ ] **Step 1: Write failing pure-state tests**

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  buildReconciliationSubmitPayload,
  reconciliationSubmitDisabled
} from '../src/pages/tiktokReconciliationState.js'

test('new products require category and attributes but conflicts are excluded', () => {
  const preview = {
    revision: 'r1',
    new_products: [{ item_key: 'new_product:1', status: 'ready' }],
    variant_additions: [{ target_product_id: 'tt-1', status: 'ready' }],
    conflicts: [{ item_key: 'conflict:1', status: 'blocked' }]
  }
  const selection = { newProductKeys: ['new_product:1'], variantProductIds: ['tt-1'] }

  assert.equal(reconciliationSubmitDisabled(preview, selection, {}), true)
  const payload = buildReconciliationSubmitPayload(preview, selection, {
    'new_product:1': { category_id: '601', attributes: [{ id: '100', values: ['x'] }] }
  })
  assert.deepEqual(payload.selected_new_product_keys, ['new_product:1'])
  assert.deepEqual(payload.selected_variant_product_ids, ['tt-1'])
  assert.equal('conflict:1' in payload, false)
})
```

- [ ] **Step 2: Verify RED**

Run: `cd frontend; npm test -- tiktokReconciliationState.test.js`

Expected: FAIL because the state module does not exist.

- [ ] **Step 3: Implement service, router, pure state, and review page**

Add the two Axios methods exactly as named. The anomaly page header gains a button labelled `Selesaikan Semua dengan Review`, disabled while preview is loading; on success, it routes with the returned run id and shows a safe error notice on failure. The review page loads/preserves the preview by run id, displays counts and collapsible groups for new products, safe additions, and blocked conflicts, and never renders a checkbox for conflicts.

For selected new products, render required category and attributes inputs keyed by item key. Display source title, images, variants, seller SKU, price, and stock as editable/readable drafts without access-token fields. The sole `Konfirmasi Proses` button posts the pure helper payload, disables while running, renders each returned item status/message, and offers `Muat Ulang Anomali` linking to `/marketplace/stock-anomalies`. On `stale_revision`, preserve user edits and show `Data katalog berubah; buat review baru sebelum mengirim.` with a button that starts a new preview.

- [ ] **Step 4: Verify GREEN**

Run: `cd frontend; npm test -- tiktokReconciliationState.test.js`

Expected: PASS; blocked conflicts are absent from the submit payload and missing new-product category/attributes disable confirmation.

### Task 5: Full Verification and Publish

**Files:**
- Modify: `backend/public/index.html`
- Modify: `backend/public/assets/*` generated Vite assets
- Modify: `docs/superpowers/plans/2026-08-21-one-click-tiktok-reconciliation.md`

**Interfaces:**
- The built app serves `/marketplace/tiktok-reconciliation-review/:runId` and references generated assets present under `backend/public/assets`.
- Production preview remains read-only; no live submit is performed without user confirmation and mandatory reviewed fields.

- [ ] **Step 1: Run focused backend tests**

Run: `cd backend; ..\backend\vendor\bin\phpunit.bat --filter (TiktokReconciliation|MarketplaceSyncServiceTest|OmnichannelControllerTest)`

Expected: PASS with preview, submit, conflict, redaction, stale-revision, and existing mapping tests green.

- [ ] **Step 2: Run complete backend and frontend suites**

Run:

```powershell
.\backend\vendor\bin\phpunit.bat
cd frontend
npm test
```

Expected: both suites PASS; do not fix unrelated failures.

- [ ] **Step 3: Build and publish the frontend**

Run:

```powershell
cd frontend
npm run build
Copy-Item -Path .\dist\assets\* -Destination ..\backend\public\assets -Force
Copy-Item -Path .\dist\index.html -Destination ..\backend\public\index.html -Force
```

Expected: Vite build succeeds and every index asset reference exists under `backend/public/assets`.

- [ ] **Step 4: Perform read-only HTTP verification**

Run:

```powershell
Invoke-WebRequest http://agnishopbjm-laravel.test/marketplace/stock-anomalies -UseBasicParsing
Invoke-WebRequest http://agnishopbjm-laravel.test/api/tiktok/reconciliation-runs/preview -Method POST -ContentType 'application/json' -Body '{}'
```

Expected: the SPA page returns HTTP 200 and the preview endpoint returns a structured JSON success or an actionable non-mutating configuration/validation response; do not call submit.

- [ ] **Step 5: Inspect final diff**

Run:

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors and only intended source, test, documentation, and generated frontend files are pending.
