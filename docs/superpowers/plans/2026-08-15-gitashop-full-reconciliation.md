# Gitashop Full Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mark a Gitashopcollection Mass Update job successful only after a fresh Agni Shop source snapshot, validated workbooks, and zero-mismatch product/variant reconciliation against Gitashopcollection.

**Architecture:** The PC worker remains responsible for visible Seller Centre upload and document-row checks. Laravel owns source refresh, immutable manifest, workbook validation, target read, field comparison, and terminal status. Target reads never overwrite Agni source tables.

**Tech Stack:** Laravel 11/PHP 8.3, PostgreSQL query builder/migrations, existing Shopee Partner API/token refresh services, Node.js/Playwright worker, Vue 3/Vite.

## Global Constraints

- Source: `shopee-agnishopbjm`; target: `shopee-gitacollectionbjm`.
- Never expose marketplace tokens, cookies, raw HTML, browser profile data, or buyer data.
- Preserve marketplace-operation lease, STB guard, serial worker, and safe resume behavior.
- Stale source data, pagination gaps, API errors, unknown image identity, or workbook coverage gaps fail closed.
- No new target product/variant creation and no blind re-upload after mismatch.
- Old jobs remain count-verified only.
- After frontend changes, rebuild Vite and publish `frontend/dist` into `backend/public`.

---

## Implementation Tasks

### Task 1: Persist Strict Audit Data

**Files:** Create `backend/database/migrations/2026_08_15_000001_create_shopee_mass_upload_reconciliation_tables.php`; modify `backend/app/Services/ShopeeMassUploadService.php`; create `backend/tests/Feature/ShopeeMassUploadReconciliationPersistenceTest.php`.

- [ ] Write failing persistence tests for source snapshots, unique target mapping, safe result states, and legacy jobs.
- [ ] Run `backend/vendor/bin/phpunit tests/Feature/ShopeeMassUploadReconciliationPersistenceTest.php`; expect failure.

- [ ] Add the migration, manifest/result tables, and safe aggregate job fields; rerun focused tests and commit `feat: persist Gitashop reconciliation audit`.

### Task 2: Refresh Source and Build Manifest

**Files:** Create `backend/app/Services/ShopeeMassUploadManifestService.php`; modify `MarketplaceApiService.php`, `MarketplaceTokenRefreshService.php`, and `ShopeeMassUploadService.php`; add `ShopeeMassUploadManifestServiceTest.php`.

- [ ] Test account-scoped refresh, pagination, lease conflict, stale source data, duplicate/blank SKU, invalid price/stock, and canonical image identity; run the new test and expect failure.

- [ ] Implement manifest preparation, persist fingerprints, fail closed on coverage gaps, rerun focused tests, and commit `feat: build fresh Gitashop upload manifests`.

### Task 3: Generate and Validate Workbooks

**Files:** Modify `ShopeeGitaMassUpdateGenerator.php`, `MarketplaceImportController.php`, and `ShopeeMassUploadService.php`; add `ShopeeMassUploadWorkbookValidationTest.php`; update `ShopeeGitaMassUpdateGeneratorTest.php`.

- [ ] Write failing tests for one-to-one manifest coverage and explicit source SKU, price, stock, product image, and variant image values in the six workbooks.

- [ ] Run the focused workbook tests and make them pass by generating from immutable manifest data, writing the verified Sales price column, reopening each XLSX, and rejecting altered/missing/extra rows; commit `feat: validate Gitashop mass update workbooks`.

### Task 4: Reconcile the Gitashop Target

**Files:** Create `ShopeeMassUploadTargetSnapshotService.php` and `ShopeeMassUploadReconciliationService.php`; modify `MarketplaceApiService.php` and `ShopeeMassUploadService.php`; add `ShopeeMassUploadReconciliationServiceTest.php`.

- [ ] Test zero-match success plus missing, duplicate, SKU, price, stock, product-image, variant-image, pagination, and API failures; expect failure before implementation.
- [ ] Implement isolated paginated target reads, bounded eventual-consistency retry, canonical image comparison, safe results, and `memverifikasi` → `selesai` only at zero mismatch or `perlu_perbaikan`; rerun tests and commit `feat: reconcile Gitashop variants after upload`.

### Task 5: Wire Worker, UI, and Release Verification

**Files:** Modify `ShopeeMassUploadController.php`, `routes/api.php`, worker `client.js`/`cli.js`, `frontend/src/services/index.js`, `gitashopMassUploadState.js`, `ImportMarketplace.vue`, related tests, and `docs/gitashop-mass-upload-worker.md`.

- [ ] Add guarded final-verification/report endpoints and worker handoff after the sixth file; test valid claim, invalid claim, and repair outcome.
- [ ] Add UI statuses/counts/report download; run frontend tests/build, publish `frontend/dist` to `backend/public`, then run all backend and worker suites plus PHP lint and `git diff --check`.
