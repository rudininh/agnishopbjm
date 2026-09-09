# Omnichannel Live Product Publish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with verification checkpoints.

**Goal:** Allow an operator to enter one product and its variants once, then publish the listing directly live to Shopee AgniShopBJM and TikTok AgniShopBJM with independent status, retry, and audit handling.

**Architecture:** Keep internal product and variant records as the canonical catalog. Add channel-specific mapping and payload adapters behind a `MarketplaceProductPublisher` contract. A publication run performs preflight validation, creates both listings in parallel-safe jobs, persists each result idempotently, and reports partial success without repeating successful work.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL, Laravel HTTP client, PHPUnit, Vue 3, Axios, Vite.

## Global Constraints

- `Stock Master` remains the source of truth for SKU and inventory synchronization.
- Primary accounts are exactly `shopee-agnishopbjm` and `tiktok-agnishopbjm`.
- The live flow never intentionally creates a marketplace draft; it requests active/live publication after validation.
- Shopee and TikTok results are independent; one success must not be retried when the other channel fails.
- Every publication request uses an idempotency key derived from the publication run and account key.
- Unmapped category, required attribute, image, warehouse, logistics, or duplicate SKU blocks publication before external mutation.
- Existing unrelated working-tree changes must remain untouched.

---

### Task 1: Add internal product variants and publication records

**Files:**
- Create: `backend/database/migrations/2026_09_07_000001_create_product_variants_and_publication_runs_tables.php`
- Create: `backend/app/Models/ProductVariant.php`
- Create: `backend/app/Models/MarketplacePublicationRun.php`
- Create: `backend/app/Models/MarketplacePublicationResult.php`
- Modify: `backend/app/Models/Product.php`
- Test: `backend/tests/Feature/OmnichannelProductPublicationPersistenceTest.php`

**Interfaces:**
- `Product::variants(): HasMany` returns `ProductVariant` rows ordered by `position`.
- `MarketplacePublicationRun::results(): HasMany` returns one result per account key.
- A variant stores `product_uuid`, `variant_name`, `sku`, `price`, `stock`, `image_url`, and `position`.
- A publication result stores `run_id`, `account_key`, `status`, `remote_product_id`, `remote_variant_ids`, `idempotency_key`, `error_code`, and `error_message`.

- [ ] Write the failing persistence test for variant relationships, independent channel results, and partial success.
- [ ] Run `cd backend; php artisan test tests/Feature/OmnichannelProductPublicationPersistenceTest.php` and verify it fails because the models/tables do not exist.
- [ ] Add the migration, models, casts, relationships, and unique constraints for `(product_uuid, sku)`, `(run_id, account_key)`, and `idempotency_key`.
- [ ] Re-run the focused test and verify it passes.

---

### Task 2: Implement preflight validation and payload contracts

**Files:**
- Create: `backend/app/Services/MarketplaceProductPreflightService.php`
- Create: `backend/app/Services/MarketplaceProductPublisher.php`
- Create: `backend/app/Services/MarketplaceProductPayloadBuilder.php`
- Modify: `backend/app/Services/MarketplaceApiService.php`
- Test: `backend/tests/Unit/Services/MarketplaceProductPreflightServiceTest.php`
- Test: `backend/tests/Unit/Services/MarketplaceProductPayloadBuilderTest.php`

**Interfaces:**
- `MarketplaceProductPreflightService::validate(Product $product, array $accountKeys): array` returns `{valid, errors}` with account-scoped error objects.
- `MarketplaceProductPayloadBuilder::forShopee(Product $product, array $context): array` returns the normalized Shopee create-item payload.
- `MarketplaceProductPayloadBuilder::forTiktok(Product $product, array $context): array` returns the normalized TikTok create-product payload.
- `MarketplaceProductPublisher::publish(Product $product, array $accountKeys, string $runId): array` returns one result per requested account and never repeats a persisted success.
- `MarketplaceApiService::createShopeeProduct(array $payload, string $idempotencyKey): array` and `createTiktokProduct(array $payload, string $idempotencyKey): array` return normalized results.

- [ ] Write failing tests for duplicate SKU rejection, account-scoped missing mappings, and complete mappings.
- [ ] Run the focused preflight test and verify the expected failure.
- [ ] Implement preflight using `MarketplaceAccountReadinessService`, the existing account registry, and required product fields without external calls.
- [ ] Write failing payload-builder tests for SKU, price, stock, image, and channel-specific field names.
- [ ] Run the focused payload test and verify the expected failure.
- [ ] Implement the builders and API methods using existing account token/context and signing helpers.
- [ ] Run both focused suites and verify they pass.

---

### Task 3: Add the direct-live publication API

**Files:**
- Create: `backend/app/Http/Controllers/MarketplaceProductPublicationController.php`
- Create: `backend/app/Http/Requests/PublishMarketplaceProductRequest.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/MarketplaceProductPublicationApiTest.php`

**Interfaces:**
- `POST /api/marketplace/products/{product}/publish` accepts `{accounts: ['shopee-agnishopbjm', 'tiktok-agnishopbjm']}`.
- Response `202` returns `run_id`, overall `status`, and account results.
- `GET /api/marketplace/products/publication-runs/{run}` returns current overall and per-account status.
- `POST /api/marketplace/products/publication-runs/{run}/retry` retries only failed accounts.

- [ ] Write failing API tests for preflight `422`, both-channel success, partial success, and retry-only-failed behavior.
- [ ] Run the focused API test and verify it fails because the routes/controller do not exist.
- [ ] Implement request validation, run creation, account-isolated publication, status derivation, and idempotency protection.
- [ ] Implement status and retry endpoints; preserve successful remote IDs.
- [ ] Re-run the focused API test and verify it passes.

---

### Task 4: Build the operator-facing live form

**Files:**
- Create: `frontend/src/pages/OmnichannelProductCreate.vue`
- Create: `frontend/src/pages/omnichannelProductPublishState.js`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/router/index.js`
- Test: `frontend/tests/omnichannelProductPublishState.test.js`

**Interfaces:**
- The form submits one normalized product with an editable variant list.
- The marketplace selector defaults to both primary accounts.
- The submit action is labeled `Publish Live ke Shopee + TikTok`.
- The result panel shows one status card per channel and exposes retry only for failed channels.

- [ ] Write failing state tests for default accounts, duplicate SKU validation, partial-result retry, and duplicate publish prevention.
- [ ] Run the focused frontend test and verify it fails because the state module does not exist.
- [ ] Add Axios methods for publish, status, and retry plus pure state helpers.
- [ ] Build the form using existing product/category styles and notification patterns.
- [ ] Add route `/products/omnichannel/create` without changing existing marketplace routes.
- [ ] Run the focused frontend test and `npm run build`.

---

### Task 5: Verify and document live prerequisites

**Files:**
- Modify: `README.md`
- Modify: `backend/README.md`

- [ ] Run PHP lint on every changed backend file.
- [ ] Run focused backend and frontend tests.
- [ ] Run the full backend and frontend suites and confirm no marketplace regressions.
- [ ] Document that both accounts must be authorized, category/attribute mappings must exist, TikTok warehouse/logistics must be available, and operators must review per-channel errors after partial success.
