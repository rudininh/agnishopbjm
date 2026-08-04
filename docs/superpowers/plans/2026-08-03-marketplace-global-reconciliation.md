# Global Marketplace Variant Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show every safe Shopee-TikTok variant anomaly on one page and synchronize only verified current data from Shopee to TikTok, with the SKU template as the final authority.

**Architecture:** A cache-only overview endpoint produces grouped products and explicit flags without marketplace requests. A guarded submit endpoint refreshes one product at a time, reclassifies fresh rows, applies only safe existing helpers, and verifies TikTok after mutation. Vue renders all anomalies in one table and has one explicit batch action.

**Tech Stack:** PHP 8, Laravel, PHPUnit, Vue 3, Vite, Node.js.

## Global Constraints

- Shopee controls variant name, image, and stock.
- Canonical SKU comes from `buildShopeeTemplateSellerSku`.
- TikTok price must remain unchanged.
- Overview is cache-only; submit refreshes every product before mutation.
- Ambiguous IDs, duplicate SKUs, missing source images, unreadable details, and stale revisions are `manual_review`.
- Delete an orphan only after exact owned-SKU proof.
- Reuse redacted audit and post-submit TikTok verification helpers.
- Build in `frontend`, then publish `index.html` and `assets/*` to `backend/public`.
- Do not click marketplace mutation controls in browser verification.

---

### Task 1: Build a Cache-Only Global Anomaly Overview

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Add `GET /api/tiktok/variant-reconciliation/overview`.
- Add `protected function buildTiktokVariantReconciliationOverview(): array`.
- Add `private function classifyTiktokVariantReconciliationOverviewRows(array $products): array`.
- A response has `summary`, grouped `products`, and a SHA-256 `revision`.
- Every row has a server `key`, source IDs, `current`, `target`, and `classification: string[]`.

- [ ] **Step 1: Write the failing classification test**

Add a test-only controller with cached rows for TikTok SKU/stock mismatch,
Shopee-template mismatch, image mismatch, and exact owned orphan.

```php
public function test_global_reconciliation_overview_lists_explicit_variant_anomalies(): void
{
    $overview = $controller->overview();
    $this->assertSame(1, $overview['summary']['tiktok_sku_mismatch']);
    $this->assertSame(1, $overview['summary']['shopee_sku_template_mismatch']);
    $this->assertSame(1, $overview['summary']['tiktok_image_mismatch']);
    $this->assertSame(1, $overview['summary']['tiktok_stock_mismatch']);
    $this->assertSame(1, $overview['summary']['tiktok_orphan']);
}
```

- [ ] **Step 2: Run RED**

```powershell
php backend\vendor\bin\phpunit --filter test_global_reconciliation_overview_lists_explicit_variant_anomalies
```

Expected: FAIL because overview does not exist.

- [ ] **Step 3: Implement overview-only assembly**

Reuse cached linkage and matching. Build target SKU with
`buildShopeeTemplateSellerSku`; compare normalized image identity and integer
stock. Do not call the Shopee refresh or TikTok-detail fetch helpers.

```php
$flags = [];
if ($shopee['model_sku'] !== $templateSku) $flags[] = 'shopee_sku_template_mismatch';
if ($shopee['model_sku'] === $templateSku && $tiktok['seller_sku'] !== $shopee['model_sku']) $flags[] = 'tiktok_sku_mismatch';
if ($this->normalizedImageIdentity($shopee['image_url']) !== $this->normalizedImageIdentity($tiktok['image_url'])) $flags[] = 'tiktok_image_mismatch';
if ((int) $shopee['stock_qty'] !== (int) $tiktok['stock_qty']) $flags[] = 'tiktok_stock_mismatch';
```

Build the revision from row keys, IDs, SKU, image identity, stock, and flags.

- [ ] **Step 4: Verify overview**

```powershell
php backend\vendor\bin\phpunit --filter 'global_reconciliation_overview|tiktok_variant_reconciliation'
```

Expected: PASS.

- [ ] **Step 5: Commit overview**

Run `git diff --check`, stage the route, controller, and controller test, then
commit with `feat: list global marketplace variant anomalies`.

### Task 2: Add Fresh-Data, Fail-Closed Batch Synchronization

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:** Replace `submitTiktokVariantReconciliation(Request $request)` HTTP
501 behavior. The request has `revision` and `row_keys`. The response returns
`updated`, `skipped`, `manual_review`, `failed`, and per-row details.

- [ ] **Step 1: Write failing stale-revision and fresh-data tests**

Use a controller subclass that counts mutation calls and replaces network
boundaries. Assert stale revision is HTTP 409 with zero mutations. Assert fresh
data causes only remaining SKU, image, and stock flags to be submitted.

```php
$response = $controller->submitTiktokVariantReconciliation($this->submitRequest('stale'));
$this->assertSame(409, $response->getStatusCode());
$this->assertSame(0, $controller->mutationCalls);
```

- [ ] **Step 2: Run RED**

```powershell
php backend\vendor\bin\phpunit --filter global_reconciliation_submit_(rejects_stale_revision|reclassifies_fresh_data)
```

Expected: FAIL because submit returns HTTP 501.

- [ ] **Step 3: Implement guarded orchestration**

Validate revision and keys, rebuild the overview, and return 409 if it changed.
Group rows by product; refresh Shopee and fetch TikTok per group; then rebuild
fresh rows. Skip changed or ambiguous identities. Correct Shopee template SKU
first. For TikTok use `buildTiktokPartialEditSkuRows`,
`buildTiktokPartialEditSkuPrice`, and `buildTiktokPartialEditSkuInventory` so
untouched SKUs and structured prices survive.

```php
$updates = [
  'seller_sku' => array_intersect($flags, ['tiktok_sku_mismatch', 'shopee_sku_template_mismatch']) ? $row['target']['seller_sku'] : null,
  'stock_qty' => in_array('tiktok_stock_mismatch', $flags, true) ? $row['target']['stock_qty'] : null,
];
```

Upload images with the established `ATTRIBUTE_IMAGE` path. Only a proven orphan
uses `buildTiktokPartialEditSkuDeleteRows`. Re-fetch TikTok and verify each
requested SKU, image, and stock before reporting `updated`.

- [ ] **Step 4: Test preservation, unsafe skips, and commit**

Use fake transport tests to prove image/stock changes retain existing IDR price;
duplicate SKU or missing image has zero mutations and increments `manual_review`.

```powershell
php backend\vendor\bin\phpunit --filter 'global_reconciliation_submit|tiktok_variant_reconciliation|bulk_tiktok'
```

Then run `git diff --check`, stage the controller and test, and commit with
`feat: sync verified marketplace variant anomalies`.

### Task 3: Add Frontend State and API Clients

**Files:**
- Create: `frontend/src/pages/globalReconciliationState.js`
- Create: `frontend/tests/globalReconciliationState.test.js`
- Modify: `frontend/src/services/index.js`

**Interfaces:** Add `tiktokVariantReconciliationOverview()` and
`tiktokVariantReconciliationSubmit(data)`. Export
`globalReconciliationBadges(flags)` and `globalReconciliationSubmitFeedback(result)`.

- [ ] **Step 1: Write failing Node tests**

```js
assert.deepEqual(globalReconciliationBadges(['tiktok_sku_mismatch', 'tiktok_stock_mismatch']), ['SKU TikTok berbeda', 'Stok TikTok berbeda'])
assert.equal(globalReconciliationSubmitFeedback({ updated: 2, skipped: 1, manual_review: 3, failed: 4 }), 'Sinkronisasi selesai. Berhasil 2 | Dilewati 1 | Review manual 3 | Gagal 4')
```

- [ ] **Step 2: Run RED, implement, verify, and commit**

```powershell
Set-Location frontend
npm test -- globalReconciliationState.test.js
```

Expected RED because the module is absent. Implement labels for all five flags
and a manual-review fallback, add service calls, run `npm test`, then commit
the three files with `feat: add global reconciliation frontend state`.

### Task 4: Replace the Selected-Product Page with One Global Table

**Files:**
- Modify: `frontend/src/pages/VariantMarketplaceReconciliation.vue`

**Interfaces:** On mount, `loadOverview()` calls the new overview endpoint.
`submitAll()` sends the overview revision plus every visible row key only after
the user presses `Sinkronkan Semua Anomali`, then reloads overview data.

- [ ] **Step 1: Replace the dropdown and preview UI**

Render summary counts and product-group rows, then variant rows with Shopee SKU,
TikTok SKU, template SKU, Shopee/TikTok stock, image thumbnail/link, and badges.
Use `globalReconciliationBadges(row.classification)`; do not hide manual review.

- [ ] **Step 2: Implement explicit submission state**

Disable the action during loading/submission and when no actionable row exists.
Ask for a native confirmation before submit. Display the server result with
`globalReconciliationSubmitFeedback`; lock controls while a request runs.

- [ ] **Step 3: Keep the table responsive**

Use full-width layout and a horizontal table scroll below the existing mobile
breakpoint. Limit thumbnails to 40px and never place long image URLs in cells.

- [ ] **Step 4: Build, publish, and commit**

```powershell
Set-Location frontend
npm run build
Set-Location ..
Copy-Item -Force frontend\dist\index.html backend\public\index.html
Copy-Item -Force frontend\dist\assets\* backend\public\assets\
```

Run `git diff --check`, stage the Vue page and generated `backend/public`
assets, then commit with `feat: show global marketplace reconciliation`.

### Task 5: Verify Locally Without Marketplace Mutation

**Files:**
- Modify: `docs/superpowers/plans/2026-08-03-marketplace-global-reconciliation.md`

- [ ] **Step 1: Run complete test suites**

```powershell
Set-Location backend
php vendor\bin\phpunit
Set-Location ..\frontend
npm test
```

Expected: both commands exit 0.

- [ ] **Step 2: Verify the overview endpoint only**

```powershell
Set-Location ..
php backend\artisan optimize:clear
Invoke-WebRequest -UseBasicParsing http://agnishopbjm-laravel.test/api/tiktok/variant-reconciliation/overview
```

Inspect non-empty `summary`, `products`, and `revision`. Do not call submit.

- [ ] **Step 3: Verify the page in a real browser**

Open `/sinkronisasi-varian-marketplace` with Playwright CLI and capture a
snapshot. Confirm no dropdown, visible grouped rows, classification badges, and
an unclicked `Sinkronkan Semua Anomali` button.

- [ ] **Step 4: Record evidence and inspect worktree**

Update this plan with actual test counts and browser observations. Run
`git diff --check`, `git status --short`, and `git log --oneline --decorate -8`.
Do not merge or push unless the user explicitly requests it.
