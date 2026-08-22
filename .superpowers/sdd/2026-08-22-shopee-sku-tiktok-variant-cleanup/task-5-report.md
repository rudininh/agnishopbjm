# Task 5 Report: Cleanup API endpoints

## RED

- Added route and revision-validation tests first. Before implementation, the feature request returned `405` and both cleanup routes were absent.
- The mapping-only preview integration test initially failed under SQLite on PostgreSQL-specific candidate-source SQL. It established the need for portable reads before the endpoint could be verified end-to-end.

## GREEN

- Added `POST /api/tiktok/bulk-missing-variants/sku-cleanup/preview` and `POST /api/tiktok/bulk-missing-variants/sku-cleanup/{runId}/submit`.
- Preview ensures the existing mapping tables, uses `tiktokBulkCandidateGroups(true)`, persists the cleanup-service preview, and sends no HTTP requests.
- Submit requires a string of exactly 64 characters, sets unlimited execution time, refreshes marketplace tokens once, recomputes current candidate groups only for a matching `ready_for_review` run, and delegates to `ShopeeSkuTiktokVariantCleanupService`.
- Submit status mapping is `not_found` 404, `stale_revision` 409, `busy` 423, and 200 for `partial`, `failed`, and `completed` results.
- The controller does not call `MarketplaceApiService`; submit integration tests bind a fake API dependency and assert the service uses persisted target product IDs.
- Made the existing candidate-source reads portable for the SQLite PHPUnit environment while preserving PostgreSQL behavior: ANSI item-ID cast, latest-action window query, and SQLite schema fallbacks that create or add missing candidate/auth columns for both empty and legacy test schemas.

## Verification

- RED: `php backend/vendor/bin/phpunit backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="(submit_requires_a_64_character_revision|shopee_sku_tiktok_cleanup_routes_are_registered)"` — 2 expected failures (405/missing routes).
- GREEN focused API and route checks: 74 tests, 254 assertions.
- `php backend/vendor/bin/phpunit backend/tests/Feature/ShopeeSkuTiktokVariantCleanupApiTest.php` — 8 tests, 62 assertions.
- `php backend/vendor/bin/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="bulk|cleanup|tiktok_delete"` — 22 tests, 67 assertions.
- `php backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeSkuTiktokVariantCleanupServiceTest.php backend/tests/Feature/TiktokReconciliationPersistenceTest.php` — 55 tests, 434 assertions.
- `php backend/artisan route:list --path=tiktok/bulk-missing-variants/sku-cleanup --method=POST` — both routes registered.
- `php backend/vendor/bin/phpunit` — 269 tests, 1370 assertions, exit 0.
- `git diff --check` for Task 5 tracked files — clean.

## Concern

- SQLite support is limited to the controller's existing runtime schema-provisioning responsibilities; the production PostgreSQL paths remain intact.
