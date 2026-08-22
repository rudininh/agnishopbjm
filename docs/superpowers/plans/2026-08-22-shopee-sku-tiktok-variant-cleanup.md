# Shopee SKU and TikTok Variant Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan aksi aman di halaman Tambah Semua Varian TikTok yang hanya menormalkan `model_sku` Shopee yang tidak sesuai nama varian terbaru dan hanya menghapus SKU TikTok lama yang berpasangan dengan perubahan tersebut.

**Architecture:** Controller tetap menjadi adapter HTTP dan memakai sumber `mapping_only_variants` yang sudah ada. Service cleanup baru membangun preview deterministik, menyimpan run/revision pada tabel reconciliation, mengelompokkan mutasi per produk TikTok, serta mengatur verifikasi dan retry. `MarketplaceApiService` menyediakan operasi katalog kecil yang dapat dipalsukan dalam test; pembangun payload TikTok terpisah memastikan semua SKU bukan target dipertahankan lengkap.

**Tech Stack:** Laravel/PHP 8, query builder, Laravel HTTP client/cache locks, PHPUnit, Vue 3 Composition API, Axios, Node test runner, Vite.

## Global Constraints

- Jangan pernah menjalankan endpoint submit live selama implementasi atau verifikasi otomatis. Hanya endpoint preview yang read-only boleh dibuka.
- Shopee request hanya boleh mengirim `item_id` dan `model[].model_id/model_sku`; jangan kirim nama, gambar, harga, stok, status, atau atribut model lain.
- TikTok request harus dibuat dari detail produk terbaru dan menghapus semua target dalam satu `partial_edit` per produk. SKU bukan target wajib tetap ada dengan seller SKU, harga, inventory, dan sales attributes yang tersedia.
- Baris dengan SKU lama yang sudah sama dengan template adalah `unchanged`: jangan update Shopee dan jangan hapus TikTok.
- Pertahankan `stock_master.tiktok_product_id` dan `sku_mappings.tiktok_product_id`; kosongkan hanya identitas SKU TikTok lama setelah delete terverifikasi. Jangan set `is_hidden_from_mapping`.
- Gunakan migration audit yang sudah ada di worktree: `2026_08_21_000002_create_tiktok_reconciliation_runs_tables.php`. Jangan membuat tabel audit duplikat.
- Jangan menyimpan `access_token`, `app_secret`, `sign`, `shop_cipher`, `authorization`, atau header kredensial lain dalam payload/result audit.
- Angka 18/90/86/83 hanyalah snapshot. Semua count harus dihitung ulang saat preview/submit.
- Karena worktree sudah berisi perubahan pengguna yang belum dilacak, stage file secara eksplisit pada setiap commit; jangan gunakan `git add -A`.

---

### Task 1: Extract a single Shopee SKU template builder

**Files:**

- Create: `backend/app/Services/ShopeeSellerSkuTemplate.php`
- Create: `backend/tests/Unit/Services/ShopeeSellerSkuTemplateTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:3267`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

- [ ] **Step 1: Write the failing template tests**

Create focused tests for the examples and normalization limits:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\ShopeeSellerSkuTemplate;
use PHPUnit\Framework\TestCase;

class ShopeeSellerSkuTemplateTest extends TestCase
{
    public function test_it_builds_the_sku_from_item_id_and_current_variant_name(): void
    {
        $builder = new ShopeeSellerSkuTemplate();

        $this->assertSame(
            'INT-54256579274-SOFT-DUSTY',
            $builder->build('54256579274', 'Soft Dusty')
        );
        $this->assertSame('INT-100-MERAH-L', $builder->build('100', 'Merah / L'));
    }

    public function test_it_normalizes_symbols_preserves_supported_dashes_and_limits_length(): void
    {
        $builder = new ShopeeSellerSkuTemplate();
        $sku = $builder->build('42', '  Rose & Dusty -- Premium  ');

        $this->assertSame('INT-42-ROSE-DUSTY----PREMIUM', $sku);
        $this->assertLessThanOrEqual(100, mb_strlen($sku));
    }
}
```

- [ ] **Step 2: Run the focused tests and confirm RED**

Run from repository root:

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSellerSkuTemplateTest.php
```

Expected: failure because `ShopeeSellerSkuTemplate` does not exist.

- [ ] **Step 3: Implement the shared builder and delegate the controller helper**

Implement:

```php
final class ShopeeSellerSkuTemplate
{
    public function build(string $itemId, string $variantName): string
    {
        $fragment = strtoupper(trim($variantName));
        $fragment = preg_replace('/[^A-Z0-9_-]+/', '-', $fragment);
        $fragment = trim((string) $fragment, '-');
        $fragment = substr($fragment !== '' ? $fragment : 'X', 0, 30);

        return 'INT-'.trim($itemId).'-'.$fragment;
    }
}
```

Keep `OmnichannelController::buildShopeeTemplateSellerSku()` as a compatibility wrapper, but implement it as `return app(ShopeeSellerSkuTemplate::class)->build($itemId, $variantName);`. This prevents existing bulk-fill and reconciliation paths from drifting from cleanup.

- [ ] **Step 4: Run focused and controller regression tests**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSellerSkuTemplateTest.php
php backend/vendor/bin/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=shopee_bulk_candidates
```

Expected: PASS and existing expected values such as `INT-100-MERAH-L` remain unchanged.

- [ ] **Step 5: Commit only Task 1 files**

```powershell
git add -- backend/app/Services/ShopeeSellerSkuTemplate.php backend/tests/Unit/Services/ShopeeSellerSkuTemplateTest.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "refactor: share Shopee seller SKU template"
```

---

### Task 2: Build and persist a deterministic cleanup preview

**Files:**

- Create: `backend/app/Services/ShopeeSkuTiktokVariantCleanupService.php`
- Create: `backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php`
- Modify: `backend/tests/Feature/TiktokReconciliationPersistenceTest.php`
- Use without duplicating: `backend/database/migrations/2026_08_21_000002_create_tiktok_reconciliation_runs_tables.php`

- [ ] **Step 1: Add failing preview classification tests**

Build database fixtures containing `shopee_product_model`, `stock_master`, `sku_mappings`, and active `tiktok_products`. Assert these cases independently:

```php
public function test_preview_marks_only_changed_template_skus_as_ready(): void
{
    $groups = collect([[
        'tiktok_product_id' => 'tt-1',
        'product_name' => 'Produk A',
        'mapping_only_variants' => collect([
            [
                'shopee_item_id' => '54256579274',
                'shopee_model_id' => 'model-khakky',
                'variant_name' => 'Khakky',
                'seller_sku' => 'INT-54256579274-SAND',
                'tiktok_sku_id' => 'tt-sku-sand',
                'tiktok_variant_name' => 'Sand',
            ],
        ]),
    ]]);

    $preview = app(ShopeeSkuTiktokVariantCleanupService::class)
        ->createPreview($groups);

    $this->assertSame(1, $preview['summary']['eligible']);
    $this->assertSame('INT-54256579274-KHAKKY', $preview['items'][0]['target_sku']);
    $this->assertSame('ready', $preview['items'][0]['status']);
}
```

Also add explicit tests for:

- old Shopee SKU equals target template -> `unchanged`, with no TikTok deletion target;
- old Shopee SKU and TikTok seller SKU do not match after trim/uppercase -> `blocked/source_sku_mismatch`;
- normalized Shopee and TikTok variant names are already equal -> `blocked/variant_names_match`;
- target is used by another model on the same Shopee item -> `blocked/shopee_target_collision`;
- target is used by another active TikTok SKU on the same product -> `blocked/tiktok_target_collision`;
- deleting all targets leaves zero active TikTok SKUs -> every ready item in that product becomes `blocked/last_tiktok_variant`;
- missing item/model/product/SKU IDs -> `blocked/incomplete_identity`;
- same source in different input order produces the same revision and stable item keys.

- [ ] **Step 2: Confirm the preview tests fail**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php --filter=preview
```

Expected: failure because the service is missing.

- [ ] **Step 3: Implement preview classification**

The service constructor should accept `ShopeeSellerSkuTemplate` and `MarketplaceApiService`. Add these public methods:

```php
public function createPreview(Collection $mappingOnlyGroups): array;
public function loadRun(string $runId): array;
public function currentRevision(Collection $mappingOnlyGroups): string;
```

Flatten only `mapping_only_variants`. Re-read the exact model and TikTok cache rows by IDs; do not trust display strings alone. Emit one audit item per variant with:

```php
[
    'item_key' => "sku-cleanup:{$itemId}:{$modelId}:{$productId}:{$tiktokSkuId}",
    'action_type' => 'shopee_sku_tiktok_delete',
    'status' => 'ready', // or unchanged/blocked
    'source_fingerprint' => hash('sha256', $canonicalSourceJson),
    'stock_master_ids' => array_values($stockMasterIds),
    'shopee_item_id' => $itemId,
    'shopee_model_id' => $modelId,
    'shopee_variant_name' => $currentName,
    'old_sku' => $oldSku,
    'target_sku' => $targetSku,
    'tiktok_product_id' => $productId,
    'tiktok_sku_id' => $tiktokSkuId,
    'tiktok_variant_name' => $tiktokName,
    'block_reason' => null,
]
```

Canonicalize arrays recursively and sort items by `item_key` before hashing. Summary keys must be `products`, `conflicts`, `eligible`, `unchanged`, and `blocked`.

- [ ] **Step 4: Persist the run and redacted item payloads**

Inside one DB transaction:

- insert UUID run with `ready_for_review` and revision;
- insert every ready/unchanged/blocked row into `tiktok_reconciliation_run_items`;
- store `target_product_id` and `target_sku_id` only for ready items;
- pass payload/result through one recursive redactor that removes secret-key matches case-insensitively.

Do not route cleanup items through `TiktokReconciliationService::loadRun()`, because that loader intentionally groups unknown action types as conflicts. Keep cleanup loading scoped to action type `shopee_sku_tiktok_delete`.

- [ ] **Step 5: Extend persistence coverage**

Add a feature assertion that the existing column widths accept the cleanup action/statuses and that duplicate `(run_id, item_key)` is rejected. Assert serialized payload does not contain any seeded marker under `access_token`, `app_secret`, `sign`, `shop_cipher`, or `authorization`.

- [ ] **Step 6: Run preview and persistence tests**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php --filter=preview
php backend/vendor/bin/phpunit backend/tests/Feature/TiktokReconciliationPersistenceTest.php
```

Expected: PASS; `Http::assertNothingSent()` confirms preview does not call either marketplace.

- [ ] **Step 7: Commit Task 2 files without sweeping unrelated worktree files**

```powershell
git add -- backend/app/Services/ShopeeSkuTiktokVariantCleanupService.php backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php backend/tests/Feature/TiktokReconciliationPersistenceTest.php backend/database/migrations/2026_08_21_000002_create_tiktok_reconciliation_runs_tables.php
git commit -m "feat: persist SKU cleanup previews"
```

---

### Task 3: Add testable catalog API operations and TikTok delete payload builder

**Files:**

- Create: `backend/app/Services/TiktokPartialEditSkuPayloadBuilder.php`
- Create: `backend/tests/Unit/Services/TiktokPartialEditSkuPayloadBuilderTest.php`
- Modify: `backend/app/Services/MarketplaceApiService.php`
- Create: `backend/tests/Unit/Services/MarketplaceApiServiceTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:9406`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

- [ ] **Step 1: Write failing payload-preservation tests**

Use a fresh detail containing three TikTok SKUs and remove two IDs in one build:

```php
$payload = app(TiktokPartialEditSkuPayloadBuilder::class)->deleteSkuIds(
    $detail,
    ['tt-old-red', 'tt-old-blue']
);

$this->assertSame('LISTING', $payload['save_mode']);
$this->assertSame(['tt-green'], array_column($payload['skus'], 'id'));
$this->assertSame('INT-42-GREEN', $payload['skus'][0]['seller_sku']);
$this->assertSame('25000', $payload['skus'][0]['price']['sale_price']);
$this->assertSame(4, $payload['skus'][0]['inventory'][0]['quantity']);
$this->assertSame($detail['skus'][2]['sales_attributes'], $payload['skus'][0]['sales_attributes']);
```

Test that the builder throws a domain/runtime exception when a target is absent or the remaining SKU list is empty. Test that duplicate target IDs are normalized and removed once.

- [ ] **Step 2: Confirm RED, then extract the current builder behavior**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/TiktokPartialEditSkuPayloadBuilderTest.php
```

Move the behavior currently represented by `buildTiktokPartialEditSkuDeleteRows()`, `buildTiktokPartialEditSkuKeepRow()`, price normalization, inventory normalization, and sales attributes into the new service. Keep controller behavior stable by delegating its private helper to the service.

- [ ] **Step 3: Add failing MarketplaceApiService contract tests**

Add tests with `Http::fake()` for four public methods:

```php
public function fetchTiktokProduct(string $productId): array;
public function partialEditTiktokProduct(string $productId, array $payload): array;
public function fetchShopeeModels(string $itemId): array;
public function updateShopeeModelSku(string $itemId, string $modelId, string $targetSku): array;
```

Assert the Shopee update request body is exactly:

```php
[
    'item_id' => 54256579274,
    'model' => [[
        'model_id' => 123,
        'model_sku' => 'INT-54256579274-SOFT-DUSTY',
    ]],
]
```

Assert it contains no `name`, `image`, `price`, `stock`, `status`, or attribute fields. Assert TikTok partial edit uses `/product/202509/products/{productId}/partial_edit`, signed query parameters, and the payload supplied by the builder.

- [ ] **Step 4: Implement the public catalog methods using existing private signing/token helpers**

Reuse `activeShopeeToken()`, `activeTiktokContext()`, `shopeeSignedGet/Post()`, and `generateTiktokSign()`. Extend the TikTok context only as needed to include shop ID/cipher already stored in the database. Normalize every return to:

```php
[
    'ok' => true,
    'message' => 'Model SKU Shopee berhasil diperbarui.',
    'data' => ['item_id' => '54256579274', 'model_id' => '123'],
    'request' => [
        'method' => 'POST',
        'path' => '/api/v2/product/update_model',
        'body' => [
            'item_id' => 54256579274,
            'model' => [['model_id' => 123, 'model_sku' => 'INT-54256579274-SOFT-DUSTY']],
        ],
    ],
    'response' => ['error' => ''],
]
```

Never include signed query strings, access tokens, or authorization headers in `request` metadata returned to the cleanup service.

- [ ] **Step 5: Run API and controller regression tests**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/TiktokPartialEditSkuPayloadBuilderTest.php
php backend/vendor/bin/phpunit backend/tests/Unit/Services/MarketplaceApiServiceTest.php
php backend/vendor/bin/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=tiktok_delete
```

Expected: PASS and every test uses an exact `Http::assertSentCount(1)` or `Http::assertNothingSent()` assertion matching its fixture.

- [ ] **Step 6: Commit Task 3**

```powershell
git add -- backend/app/Services/TiktokPartialEditSkuPayloadBuilder.php backend/tests/Unit/Services/TiktokPartialEditSkuPayloadBuilderTest.php backend/app/Services/MarketplaceApiService.php backend/tests/Unit/Services/MarketplaceApiServiceTest.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "refactor: expose safe marketplace variant operations"
```

---

### Task 4: Implement claimed execution, verification, local reconciliation, and retry

**Files:**

- Modify: `backend/app/Services/ShopeeSkuTiktokVariantCleanupService.php`
- Modify: `backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php`
- Create: `backend/database/migrations/2026_08_22_000001_add_execution_lease_to_tiktok_reconciliation_run_items.php`
- Modify: `backend/tests/Feature/TiktokReconciliationPersistenceTest.php`

- [ ] **Step 1: Add failing stale-revision and idempotent claim tests**

Add:

```php
public function test_submit_rejects_a_stale_ready_run_before_any_marketplace_write(): void
{
    $preview = $this->createCleanupPreview();
    DB::table('shopee_product_model')->where('model_id', 'model-a')->update(['name' => 'Changed Again']);

    $result = $this->service()->submit($preview['run_id'], $preview['revision'], $this->currentGroups());

    $this->assertSame('stale_revision', $result['status']);
    $this->api->shouldNotHaveReceived('partialEditTiktokProduct');
    $this->api->shouldNotHaveReceived('updateShopeeModelSku');
}
```

Also assert a second submit on `completed` returns saved results without marketplace calls.

- [ ] **Step 2: Implement atomic run claim plus renewable per-product ownership**

Add:

```php
public function submit(string $runId, string $revision, Collection $currentGroups): array;
```

Use `Cache::lock('shopee-sku-tiktok-cleanup', 900)->block(5, fn () => $this->submitLocked($runId, $revision, $currentGroups))`. Within a DB transaction, claim only `ready_for_review` with matching persisted and current revisions. Return an HTTP-neutral status (`stale_revision`, `busy`, `not_found`, `claimed`, `partial`, or `completed`) for the controller to map later.

Add `execution_owner`, `execution_lease_until`, and `execution_attempts` to reconciliation run items. Before processing a product group, lock every scoped item row with `lockForUpdate()`, reject an unexpired owner, then atomically assign one UUID owner and a 15-minute lease to the whole group. Renew that lease inside a short transaction immediately before every marketplace network call and release it after the product reaches a persisted outcome. A later worker may take over only after lease expiry; persisted delete-intent and survivor evidence must still prevent a second TikTok delete after takeover.

For a run already `claimed` or `partial`, use persisted ready/partial audit items and fresh remote verification rather than rebuilding a delete target from the changed local candidate list.

Add concurrency regressions proving an expired global cache lock does not allow a second owner to claim the same unexpired product group, and that an expired product lease can be taken over without erasing previously persisted TikTok delete evidence.

- [ ] **Step 3: Add failing TikTok group execution tests**

Mock `MarketplaceApiService` and assert:

- one product with two eligible targets causes exactly one `partialEditTiktokProduct()` call;
- the payload omits only those two target IDs;
- a fresh verification read confirms targets absent and all expected non-target IDs present;
- TikTok failure or unverified deletion causes zero Shopee updates;
- disappearance of a non-target SKU marks the entire product `submitted_unverified` and blocks Shopee.

- [ ] **Step 4: Implement TikTok-first grouped deletion and verification**

For each `tiktok_product_id`:

1. fetch fresh TikTok detail and Shopee model lists;
2. recheck model ID/name/old SKU/target SKU and both collision rules;
3. calculate target IDs still present;
4. if all targets are already absent on a partial retry, skip delete and continue verification;
5. otherwise build one payload via `TiktokPartialEditSkuPayloadBuilder` and submit once;
6. fetch TikTok detail again and require every target absent plus every pre-submit non-target ID present.

If any precondition fails, record all product items as blocked/failed without mutating that product.

- [ ] **Step 5: Add failing Shopee-only-field and local-state tests**

Assert TikTok deletion verification precedes each Shopee call. On confirmed Shopee success, verify database state:

```php
$this->assertDatabaseHas('shopee_product_model', [
    'item_id' => '54256579274',
    'model_id' => 'model-a',
    'model_sku' => 'INT-54256579274-SOFT-DUSTY',
]);
$this->assertDatabaseHas('stock_master', [
    'id' => $stockMasterId,
    'shopee_seller_sku' => 'INT-54256579274-SOFT-DUSTY',
    'tiktok_product_id' => 'tt-1',
    'tiktok_sku' => null,
    'tiktok_seller_sku' => null,
    'is_hidden_from_mapping' => false,
]);
$this->assertDatabaseHas('sku_mappings', [
    'stock_master_id' => $stockMasterId,
    'tiktok_product_id' => 'tt-1',
    'tiktok_sku_id' => null,
    'tiktok_sku_name' => null,
    'tiktok_image_url' => null,
]);
```

Also assert the deleted `tiktok_products` row becomes inactive with zero stock, without touching other rows.

- [ ] **Step 6: Implement Shopee update, fresh verification, and per-variant DB transactions**

For every TikTok-verified target:

- call `updateShopeeModelSku()` with only item/model/target SKU;
- fetch models again and require the same model ID to report the target SKU;
- if verified, update Shopee cache and local TikTok-link fields inside one DB transaction;
- if the request succeeds but verification does not, store `submitted_unverified` and do not write the target SKU locally;
- redact request/response before writing item result.

After a verified TikTok delete, reflect the TikTok deletion locally even when Shopee fails, while retaining `tiktok_product_id`. Mark the item `partial` so retry knows to verify absence and retry only Shopee.

- [ ] **Step 7: Add and implement retry tests**

Test first submit: TikTok succeeds, Shopee fails -> run/item `partial`. Test second submit of the same run/revision: TikTok detail shows the old SKU absent, `partialEditTiktokProduct()` is never called, Shopee update is retried, and the run becomes `completed` after verification.

Aggregate final status using:

- `completed` when every ready item is `updated` and blocked/unchanged items were never submitted;
- `partial` when any item is `partial` or `submitted_unverified`;
- `failed` only when no eligible item succeeded and retryable failures remain.

- [ ] **Step 8: Run all cleanup service tests**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php
```

Expected: PASS with no unfaked HTTP request.

- [ ] **Step 9: Commit Task 4**

```powershell
git add -- backend/app/Services/ShopeeSkuTiktokVariantCleanupService.php backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php
git commit -m "feat: execute retryable SKU cleanup"
```

---

### Task 5: Expose preview and submit endpoints through the existing candidate source

**Files:**

- Modify: `backend/routes/api.php:52`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:8536`
- Create: `backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

- [ ] **Step 1: Write failing route and validation tests**

Assert registration of:

```text
POST api/tiktok/bulk-missing-variants/sku-cleanup/preview
POST api/tiktok/bulk-missing-variants/sku-cleanup/{runId}/submit
```

Assert submit requires a 64-character revision string and returns 422 for a missing revision. Assert service statuses map to:

- `not_found` -> 404;
- `stale_revision` -> 409;
- `busy` -> 423;
- `partial`, `failed`, or `completed` -> 200 with per-item results.

- [ ] **Step 2: Confirm RED**

```powershell
php backend/vendor/bin/phpunit backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php
```

- [ ] **Step 3: Add thin controller methods and routes**

Preview method:

```php
public function previewShopeeSkuTiktokCleanup(
    ShopeeSkuTiktokVariantCleanupService $service
): JsonResponse {
    $this->ensureSkuMappingTables();
    $groups = $this->tiktokBulkCandidateGroups(true);

    return response()->json($service->createPreview($groups));
}
```

Submit method validates revision, sets unlimited execution time, calls `autoRefreshMarketplaceTokens()` once, recomputes the same groups for the ready-run revision check, and delegates all ordering/mutations to the service.

- [ ] **Step 4: Prove preview is read-only and submit uses exact persisted targets**

In the feature test, seed a small mapping-only fixture and call preview with `Http::fake()`. Assert a run is created, counts are correct, and `Http::assertNothingSent()`. Bind/mock the API service for submit tests; do not configure real credentials.

- [ ] **Step 5: Run routes, feature tests, and relevant regressions**

```powershell
php backend/vendor/bin/phpunit backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php
php backend/vendor/bin/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="bulk|cleanup|tiktok_delete"
php backend/vendor/bin/phpunit backend/tests/Unit/Services/TiktokReconciliationServiceTest.php backend/tests/Feature/TiktokReconciliationPersistenceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit Task 5**

```powershell
git add -- backend/routes/api.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: expose SKU cleanup workflow"
```

---

### Task 6: Add frontend API methods and pure cleanup state helpers

**Files:**

- Create: `frontend/src/pages/shopeeSkuTiktokCleanupState.js`
- Create: `frontend/tests/shopeeSkuTiktokCleanupState.test.js`
- Modify: `frontend/src/services/index.js:160`

- [ ] **Step 1: Write failing state-helper tests**

Cover summary formatting, submit enablement, tone, and preservation of a retryable run:

```js
test('allows submit only for a reviewed run with eligible rows', () => {
  assert.equal(canSubmitSkuCleanup({
    loading: false,
    submitting: false,
    preview: { run_id: 'run-1', revision: 'a'.repeat(64), summary: { eligible: 2 } }
  }), true)
})

test('formats partial results and keeps retry available', () => {
  const state = mergeSkuCleanupResult({}, {
    status: 'partial',
    summary: { updated: 1, partial: 1, blocked: 3 }
  })

  assert.equal(state.tone, 'warning')
  assert.equal(state.canRetry, true)
})
```

Also test `stale_revision` produces an error message instructing the user to generate a new preview.

- [ ] **Step 2: Confirm RED, then implement pure helpers**

```powershell
Set-Location frontend
node --test tests/shopeeSkuTiktokCleanupState.test.js
```

Keep all branching/count formatting in the pure module so the Vue component mostly wires UI events.

- [ ] **Step 3: Add API client methods**

```js
previewShopeeSkuTiktokCleanup() {
  return api.post('/tiktok/bulk-missing-variants/sku-cleanup/preview')
},
submitShopeeSkuTiktokCleanup(runId, revision) {
  return api.post(`/tiktok/bulk-missing-variants/sku-cleanup/${encodeURIComponent(runId)}/submit`, { revision })
}
```

- [ ] **Step 4: Run frontend unit tests**

```powershell
npm test
```

Expected: all Node tests pass.

- [ ] **Step 5: Commit Task 6**

```powershell
Set-Location ..
git add -- frontend/src/pages/shopeeSkuTiktokCleanupState.js frontend/tests/shopeeSkuTiktokCleanupState.test.js frontend/src/services/index.js
git commit -m "feat: add SKU cleanup frontend state"
```

---

### Task 7: Add the preview modal, explicit warning, execution results, and retry UI

**Files:**

- Modify: `frontend/src/pages/BulkTambahVarianTiktok.vue`
- Modify: `frontend/tests/shopeeSkuTiktokCleanupState.test.js`

- [ ] **Step 1: Add state transitions required by the component**

Extend pure tests for:

- cleanup button disabled while bulk-add/cleanup is loading or submitting;
- preview separates `ready`, `unchanged`, and `blocked` rows;
- submit result exposes per-row statuses `updated`, `partial`, `submitted_unverified`, `blocked`, `stale_revision`, and `failed`;
- a successful completion closes the modal and requests refresh;
- a partial result retains `run_id/revision` and enables **Coba Lagi yang Belum Selesai**.

- [ ] **Step 2: Implement the cleanup button and preview loading**

In the **SKU TikTok Sudah Ada, Mapping Belum Tersambung** header, add:

```html
<button
  class="danger"
  type="button"
  :disabled="loading || submitting || cleanupLoading || cleanupSubmitting"
  @click="openSkuCleanupPreview"
>
  Normalisasi SKU &amp; Hapus Varian TikTok Lama
</button>
```

`openSkuCleanupPreview()` calls only the preview endpoint, stores `run_id/revision/items/summary`, and then opens the modal. Do not infer eligible rows from the table already rendered.

- [ ] **Step 3: Render an explicit confirmation modal**

Show summary counts plus a table with product, Shopee item/model, current variant name, old SKU, target SKU, TikTok SKU ID/name, and status/reason. The modal must state all five facts:

1. hanya `model_sku` Shopee yang diubah;
2. hanya varian TikTok yang tercantum sebagai **Siap** yang dihapus;
3. varian TikTok lain dipertahankan;
4. varian tidak dibuat ulang otomatis;
5. submit dapat ditolak bila katalog berubah setelah preview.

Disable confirmation when `summary.eligible === 0`.

- [ ] **Step 4: Wire submit, partial retry, and candidate refresh**

Send exactly the modal's `run_id` and `revision`. After `completed`, close the modal and call existing `loadPreview({ preserveFeedback: true })`; successful rows should then appear in the existing add-candidate table because the TikTok product ID was retained but old SKU ID was cleared.

For `partial`, keep the modal/result open and show **Coba Lagi yang Belum Selesai** using the same run/revision. For HTTP 409, discard the stale run and instruct the user to click preview again.

- [ ] **Step 5: Verify accessibility and visual states without live submission**

Ensure the modal has `role="dialog"`, `aria-modal="true"`, a labelled heading, keyboard-focusable buttons, and clear `ready/unchanged/blocked/partial/error` badges. Keep the destructive button visually distinct from the existing **Tambahkan Varian ke TikTok** action.

- [ ] **Step 6: Run frontend tests and build**

```powershell
Set-Location frontend
npm test
npm run build
```

Expected: tests and Vite build exit 0.

- [ ] **Step 7: Commit Task 7 source files**

```powershell
Set-Location ..
git add -- frontend/src/pages/BulkTambahVarianTiktok.vue frontend/tests/shopeeSkuTiktokCleanupState.test.js
git commit -m "feat: add SKU cleanup confirmation UI"
```

---

### Task 8: Full verification, publish the frontend, and inspect the local read-only flow

**Files:**

- Modify generated: `backend/public/index.html`
- Create/modify generated: `backend/public/assets/index-*.js`
- Create/modify generated: `backend/public/assets/index-*.css`
- Verify: all files changed by Tasks 1-7

- [ ] **Step 1: Run PHP syntax checks on changed PHP files**

```powershell
$phpFiles = git diff --name-only 85091a5..HEAD -- '*.php'
$phpFiles | ForEach-Object { php -l $_ }
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Run focused backend safety tests**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSellerSkuTemplateTest.php backend/tests/Unit/Services/TiktokPartialEditSkuPayloadBuilderTest.php backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php
```

Expected: PASS; all marketplace HTTP is faked.

- [ ] **Step 3: Run complete relevant suites**

```powershell
php backend/vendor/bin/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php backend/tests/Unit/Services/MarketplaceApiServiceTest.php backend/tests/Unit/Services/TiktokReconciliationServiceTest.php backend/tests/Feature/TiktokReconciliationPersistenceTest.php backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php
Set-Location frontend
npm test
npm run build
Set-Location ..
```

Expected: all exit 0.

- [ ] **Step 4: Publish the generated frontend safely**

From repository root:

```powershell
Copy-Item -LiteralPath frontend\dist\index.html -Destination backend\public\index.html -Force
Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets -Recurse -Force
```

Parse every `/assets/` reference in `backend/public/index.html` and confirm its exact referenced filename exists under `backend/public/assets`. Do not recursively delete old assets as part of this task.

- [ ] **Step 5: Inspect the local page using the browser skill**

Open `http://agnishopbjm-laravel.test/tambah-semua-varian-tiktok` and verify:

- existing bulk-add candidates still load;
- mapping-only section still shows its current dynamic count;
- cleanup button opens a read-only preview modal;
- modal shows eligible/unchanged/blocked counts and exact old/target SKU pairs;
- the confirm button is disabled if no eligible rows;
- no submit button is clicked during verification.

Inspect the browser network log and confirm opening the modal only sent `POST /api/tiktok/bulk-missing-variants/sku-cleanup/preview`, never `POST /api/tiktok/bulk-missing-variants/sku-cleanup/{runId}/submit` and never a marketplace mutation request.

- [ ] **Step 6: Check diff hygiene and generated references**

```powershell
git diff --check
git status --short
```

Review that no `.env`, token recovery file, `output/`, `.phpunit.result.cache`, credentials, or unrelated user changes are staged.

- [ ] **Step 7: Commit only the published build**

```powershell
git add -- backend/public/index.html backend/public/assets
git commit -m "build: publish SKU cleanup interface"
```

- [ ] **Step 8: Final completion audit**

Before claiming completion:

- re-run the focused backend cleanup tests and `npm test`;
- confirm the latest Vite build is the one referenced by `backend/public/index.html`;
- confirm no live submit occurred;
- verify the final diff implements every success criterion in `docs/superpowers/specs/2026-08-22-shopee-sku-tiktok-variant-cleanup-design.md`;
- use `codex-global-memory` again and record only durable, verified architecture/test facts—never `.env` values or marketplace/customer data.
