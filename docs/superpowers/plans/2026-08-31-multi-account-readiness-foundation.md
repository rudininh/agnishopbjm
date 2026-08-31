# Multi-Account Marketplace Readiness Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare safe, account-aware Shopee/TikTok configuration, normalized listing mappings, readiness reporting, and Automatic Synchronization UI for Shopee AgniShopBJM, TikTok AgniShopBJM, and Shopee GitaCollectionBJM without enabling new live stock or order mutations.

**Architecture:** A dedicated registry resolves public account metadata separately from secret server-side API context. A normalized `marketplace_listings` table establishes one Stock Master mapping per account and backfills verified legacy mappings. A readiness service combines credentials, active authorization, shop/warehouse identity, and mapping counts into sanitized account cards returned by the existing auto-sync dashboard endpoint.

**Tech Stack:** Laravel/PHP 8, Laravel configuration and query builder, PHPUnit with SQLite, Vue 3 Composition API, Vite, Node test runner.

## Global Constraints

- Stock Master remains the only inventory source.
- The only accounts in this phase are `shopee-agnishopbjm`, `tiktok-agnishopbjm`, and `shopee-gitacollectionbjm`.
- Do not add TikTok GitaCollectionBJM.
- Do not enable new multi-account order polling, stock push, reconciliation mutation, or retry workers in this phase.
- Shopee Gita must fail closed until credentials, authorization, shop identity, and mapping readiness pass.
- Gita may use the primary Shopee application only when `SHOPEE_GITA_USE_PRIMARY_APP=true`; partial or implicit fallback is forbidden.
- Partner Keys, app secrets, access tokens, and refresh tokens must never appear in frontend payloads, logs, test failure messages, or documentation values.
- Existing primary Shopee/TikTok behavior and current API routes remain backward compatible.

## File Structure

- Create `backend/config/marketplace_accounts.php`: declarative three-account registry and Gita credential/fallback configuration.
- Create `backend/app/Services/MarketplaceAccountRegistry.php`: safe public account summaries and private server-side credential context resolution.
- Create `backend/tests/Unit/Services/MarketplaceAccountRegistryTest.php`: configuration, fallback, validation, and redaction tests.
- Modify `backend/app/Http/Controllers/OmnichannelController.php`: use account-specific Shopee context for auth, callback exchange, and refresh while preserving primary defaults.
- Modify `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`: account-specific Shopee signing and fail-closed regressions.
- Create `backend/database/migrations/2026_08_31_000001_create_marketplace_listings_table.php`: normalized account-aware listing persistence and legacy backfill.
- Create `backend/tests/Feature/MarketplaceListingsMigrationTest.php`: schema, uniqueness, and backfill verification.
- Create `backend/app/Services/MarketplaceAccountReadinessService.php`: sanitized readiness computation.
- Modify `backend/app/Services/MarketplaceSyncService.php`: expose account readiness in the existing dashboard payload without changing its existing constructor signature.
- Create `backend/tests/Feature/MarketplaceAccountReadinessTest.php`: dashboard readiness contract and secret-redaction coverage.
- Create `frontend/src/pages/marketplaceAccountReadinessState.js`: pure account-card normalization and action-lock policy.
- Create `frontend/tests/marketplaceAccountReadinessState.test.js`: state and lock-policy tests.
- Modify `frontend/src/pages/MarketplaceAutoSync.vue`: account flow summary and readiness cards.
- Modify `backend/.env.example`: blank multi-account credential and enablement keys.
- Modify `docs/STB_CONFIG_REFERENCE.md`: PC/STB setup and safe activation checklist.

---

### Task 1: Account Registry and Explicit Shopee Credential Resolution

**Files:**

- Create: `backend/config/marketplace_accounts.php`
- Create: `backend/app/Services/MarketplaceAccountRegistry.php`
- Create: `backend/tests/Unit/Services/MarketplaceAccountRegistryTest.php`

**Interfaces:**

- Produces: `MarketplaceAccountRegistry::publicAccounts(): array`
- Produces: `MarketplaceAccountRegistry::account(string $accountKey): array`
- Produces: `MarketplaceAccountRegistry::shopeeContext(string $accountKey): array{partner_id:int,partner_key:string,host:string,redirect_url:string}`
- Produces: `MarketplaceAccountRegistry::tiktokContext(string $accountKey): array{app_key:string,app_secret:string,auth_host:string,api_host:string,redirect_url:string,warehouse_id:string}`
- Throws: `InvalidArgumentException` for unknown keys or channel mismatch; `RuntimeException` with a sanitized message for incomplete credentials.

- [ ] **Step 1: Write failing registry tests**

```php
<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceAccountRegistry;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MarketplaceAccountRegistryTest extends TestCase
{
    public function test_public_registry_has_exact_accounts_and_no_secrets(): void
    {
        Config::set('marketplace_accounts.accounts.shopee-agnishopbjm.credentials.partner_key', 'never-return-me');

        $accounts = app(MarketplaceAccountRegistry::class)->publicAccounts();

        $this->assertSame([
            'shopee-agnishopbjm',
            'tiktok-agnishopbjm',
            'shopee-gitacollectionbjm',
        ], array_column($accounts, 'key'));
        $this->assertStringNotContainsString('never-return-me', json_encode($accounts));
    }

    public function test_gita_uses_only_complete_specific_credentials_by_default(): void
    {
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app', false);
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials', [
            'partner_id' => 9988,
            'partner_key' => 'gita-secret',
            'host' => 'https://partner.shopeemobile.com',
            'redirect_url' => 'https://example.test/api/shopee/callback',
        ]);

        $context = app(MarketplaceAccountRegistry::class)->shopeeContext('shopee-gitacollectionbjm');

        $this->assertSame(9988, $context['partner_id']);
        $this->assertSame('gita-secret', $context['partner_key']);
    }

    public function test_gita_explicit_primary_app_fallback_is_all_or_nothing(): void
    {
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app', true);
        Config::set('marketplace_accounts.accounts.shopee-agnishopbjm.credentials', [
            'partner_id' => 2013107,
            'partner_key' => 'primary-secret',
            'host' => 'https://partner.shopeemobile.com',
            'redirect_url' => 'https://example.test/api/shopee/callback',
        ]);

        $context = app(MarketplaceAccountRegistry::class)->shopeeContext('shopee-gitacollectionbjm');

        $this->assertSame(2013107, $context['partner_id']);
        $this->assertSame('primary-secret', $context['partner_key']);
    }

    public function test_incomplete_gita_credentials_fail_with_sanitized_message(): void
    {
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app', false);
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_id', 9988);
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_key', 'do-not-leak');
        Config::set('marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.redirect_url', '');

        try {
            app(MarketplaceAccountRegistry::class)->shopeeContext('shopee-gitacollectionbjm');
            $this->fail('Expected incomplete configuration to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Konfigurasi Shopee GitaCollectionBJM belum lengkap.', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-leak', $exception->getMessage());
        }
    }
}
```

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd backend; php artisan test tests/Unit/Services/MarketplaceAccountRegistryTest.php`

Expected: FAIL because `MarketplaceAccountRegistry` and `marketplace_accounts` do not exist.

- [ ] **Step 3: Add the declarative account configuration**

Implement `backend/config/marketplace_accounts.php` with exactly three ordered accounts. Use existing primary `shopee.*` and `tiktok.*` values, plus these Gita settings:

```php
'shopee-gitacollectionbjm' => [
    'name' => 'Shopee GitaCollectionBJM',
    'channel' => 'shopee',
    'enabled' => filter_var(env('SHOPEE_GITA_ENABLED', true), FILTER_VALIDATE_BOOL),
    'use_primary_app' => filter_var(env('SHOPEE_GITA_USE_PRIMARY_APP', false), FILTER_VALIDATE_BOOL),
    'credentials' => [
        'partner_id' => (int) env('SHOPEE_GITA_PARTNER_ID', 0),
        'partner_key' => trim((string) env('SHOPEE_GITA_PARTNER_KEY', '')),
        'host' => rtrim(trim((string) env('SHOPEE_GITA_HOST', 'https://partner.shopeemobile.com')), '/'),
        'redirect_url' => trim((string) env('SHOPEE_GITA_REDIRECT_URL', '')),
    ],
    'required_env' => [
        'SHOPEE_GITA_PARTNER_ID',
        'SHOPEE_GITA_PARTNER_KEY',
        'SHOPEE_GITA_REDIRECT_URL',
    ],
],
```

Primary accounts default to enabled. Keep the Gita account enabled at the registry level so its status is `waiting_credentials`; readiness gates all operational actions.

- [ ] **Step 4: Implement the registry with separate public and secret paths**

`publicAccounts()` returns only `key`, `name`, `channel`, `enabled`, `connect_action`, `uses_primary_app`, and `required_env`. `shopeeContext()` validates all four resolved fields and never includes secret values in exceptions. `tiktokContext()` validates the one TikTok account and its app/warehouse configuration.

- [ ] **Step 5: Run focused and full registry-adjacent tests**

Run: `cd backend; php artisan test tests/Unit/Services/MarketplaceAccountRegistryTest.php tests/Feature/MarketplaceTokenSyncTest.php`

Expected: PASS.

- [ ] **Step 6: Commit Task 1**

```powershell
git add backend/config/marketplace_accounts.php backend/app/Services/MarketplaceAccountRegistry.php backend/tests/Unit/Services/MarketplaceAccountRegistryTest.php
git commit -m "feat: add marketplace account registry"
```

---

### Task 2: Account-Aware Shopee Authorization and Refresh

**Files:**

- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**

- Consumes: `MarketplaceAccountRegistry::shopeeContext(string $accountKey): array`
- Preserves: all existing token action route names and the default primary account behavior.
- Changes private methods to carry account identity consistently:
  - `buildShopeeAuthUrl(array $account): string`
  - `exchangeShopeeToken(object $callback): array`
  - `refreshShopeeTokenUnlocked(array $account): array`
  - `shopeeConfig(?array $account = null): array`

- [ ] **Step 1: Add failing account-signing regressions**

Add tests that configure distinct primary and Gita Partner IDs/Keys, invoke `auth-shopee-gitacollectionbjm`, and assert the URL contains the Gita Partner ID and a signature calculated with the Gita key. Add a refresh test with `Http::fake()` that asserts the Gita refresh request uses the Gita Partner ID. Add a missing-Gita-config test that expects HTTP 422 and a sanitized message with no configured secret.

Core auth assertion:

```php
$response = $this->postJson('/api/omnichannel/auth-shopee-gitacollectionbjm')->assertOk();
$query = [];
parse_str(parse_url($response->json('redirect_url'), PHP_URL_QUERY), $query);

$this->assertSame('9988', $query['partner_id']);
$this->assertSame(
    hash_hmac('sha256', '9988/api/v2/shop/auth_partner'.$query['timestamp'], 'gita-secret'),
    $query['sign']
);
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `cd backend; php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=shopee_account`

Expected: FAIL because Gita auth and refresh still resolve the global Shopee configuration.

- [ ] **Step 3: Inject and use `MarketplaceAccountRegistry`**

Add a controller constructor dependency or resolve the registry through a focused private accessor compatible with existing direct controller construction in tests. Replace the duplicated `MARKETPLACE_ACCOUNTS` source with public registry metadata while preserving exact action names.

Pass the account into every credential-sensitive operation:

```php
private function shopeeConfig(?array $account = null): array
{
    $accountKey = (string) ($account['key'] ?? 'shopee-agnishopbjm');

    return app(MarketplaceAccountRegistry::class)->shopeeContext($accountKey);
}
```

For callback token exchange, resolve the account from `callback->account_key` before selecting credentials. Do not infer credentials only from the stored Partner ID. Preserve the callback `account` query parameter and store the resolved Partner ID.

- [ ] **Step 4: Verify Gita failure does not affect primary Shopee**

Run the focused tests with Gita credentials incomplete and primary credentials complete. Confirm primary auth remains HTTP 200 while Gita returns HTTP 422.

- [ ] **Step 5: Run the full Omnichannel controller suite**

Run: `cd backend; php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

Expected: PASS with no secret values in output.

- [ ] **Step 6: Commit Task 2**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "fix: resolve Shopee credentials per account"
```

---

### Task 3: Normalized Account-Aware Marketplace Listings

**Files:**

- Create: `backend/database/migrations/2026_08_31_000001_create_marketplace_listings_table.php`
- Create: `backend/tests/Feature/MarketplaceListingsMigrationTest.php`

**Interfaces:**

- Produces table: `marketplace_listings`
- Unique logical identity: `(stock_master_id, account_key)`
- Unique remote identity: `(account_key, remote_identity_hash)`
- Backfills legacy mappings into the primary Shopee and TikTok account keys only.

- [ ] **Step 1: Write failing migration and backfill tests**

```php
public function test_marketplace_listings_are_account_aware_and_backfill_primary_mappings(): void
{
    Schema::dropIfExists('marketplace_listings');
    if (! Schema::hasTable('stock_master')) {
        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku')->unique();
            $table->integer('stock_qty')->default(0);
            $table->timestamps();
        });
    }
    DB::table('stock_master')->insert([
        'id' => 10,
        'internal_sku' => 'INT-RED',
        'stock_qty' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sku_mappings')->insert([
        'stock_master_id' => 10,
        'shopee_item_id' => 'item-1',
        'shopee_model_id' => 'model-1',
        'tiktok_product_id' => 'product-1',
        'tiktok_sku_id' => 'sku-1',
        'seller_sku' => 'INT-RED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_08_31_000001_create_marketplace_listings_table.php');
    $migration->up();

    $this->assertDatabaseHas('marketplace_listings', [
        'stock_master_id' => 10,
        'account_key' => 'shopee-agnishopbjm',
        'remote_product_id' => 'item-1',
        'remote_variant_id' => 'model-1',
    ]);
    $this->assertDatabaseHas('marketplace_listings', [
        'stock_master_id' => 10,
        'account_key' => 'tiktok-agnishopbjm',
        'remote_product_id' => 'product-1',
        'remote_variant_id' => 'sku-1',
    ]);
}
```

Import `Illuminate\Database\Schema\Blueprint`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Support\Facades\DB`, and `Illuminate\Support\Facades\Schema`. Use `RefreshDatabase`, then drop only `marketplace_listings` before arranging the legacy fixture and invoking the migration object. Also test that the same remote IDs can exist in two account keys, while duplicate `(stock_master_id, account_key)` rows fail.

- [ ] **Step 2: Run the migration test and verify RED**

Run: `cd backend; php artisan test tests/Feature/MarketplaceListingsMigrationTest.php`

Expected: FAIL because the dated migration file does not exist yet, so the test cannot create or query `marketplace_listings`.

- [ ] **Step 3: Implement the table and idempotent backfill**

Create these columns:

```php
$table->id();
$table->unsignedBigInteger('stock_master_id');
$table->string('account_key', 100);
$table->string('channel', 20);
$table->string('remote_product_id', 100);
$table->string('remote_variant_id', 100)->default('');
$table->char('remote_identity_hash', 64);
$table->string('seller_sku', 150)->nullable();
$table->string('warehouse_id', 100)->nullable();
$table->boolean('is_active')->default(true);
$table->timestamps();
$table->unique(['stock_master_id', 'account_key']);
$table->unique(['account_key', 'remote_identity_hash']);
$table->index(['account_key', 'is_active']);
```

Calculate `remote_identity_hash` as SHA-256 over a collision-free JSON encoding of channel, remote product ID, and remote variant ID. Backfill only rows with a non-empty product and variant identity. Use `upsert` so re-running the migration helper cannot duplicate data. Do not add a foreign key because existing deployments and test fixtures create `stock_master` outside one canonical migration.

- [ ] **Step 4: Run focused migration tests**

Run: `cd backend; php artisan test tests/Feature/MarketplaceListingsMigrationTest.php`

Expected: PASS.

- [ ] **Step 5: Run current mapping and STB regression suites**

Run: `cd backend; php artisan test tests/Feature/SkuMappingsMigrationTest.php tests/Unit/Services/StbMappingSyncServiceTest.php`

Expected: PASS; legacy mapping behavior remains intact.

- [ ] **Step 6: Commit Task 3**

```powershell
git add backend/database/migrations/2026_08_31_000001_create_marketplace_listings_table.php backend/tests/Feature/MarketplaceListingsMigrationTest.php
git commit -m "feat: add account-aware marketplace listings"
```

---

### Task 4: Sanitized Per-Account Readiness API

**Files:**

- Create: `backend/app/Services/MarketplaceAccountReadinessService.php`
- Modify: `backend/app/Services/MarketplaceSyncService.php`
- Create: `backend/tests/Feature/MarketplaceAccountReadinessTest.php`

**Interfaces:**

- Consumes: `MarketplaceAccountRegistry::publicAccounts()` and server-side credential contexts.
- Produces: `MarketplaceAccountReadinessService::all(): array`
- Adds to `GET /api/marketplace/auto-sync` response: `data.accounts: array`
- Each result contains exactly: `key`, `name`, `channel`, `state`, `message`, `checks`, `mapped_skus`, `connect_action`, `required_env`.

- [ ] **Step 1: Write failing readiness contract tests**

Cover these cases independently:

```php
public function test_gita_waits_for_credentials_without_leaking_secrets(): void
{
    config([
        'marketplace_accounts.accounts.shopee-gitacollectionbjm.enabled' => true,
        'marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app' => false,
        'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_id' => 0,
        'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_key' => 'never-leak',
        'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.redirect_url' => '',
    ]);

    $response = $this->getJson('/api/marketplace/auto-sync')->assertOk();
    $gita = collect($response->json('data.accounts'))->firstWhere('key', 'shopee-gitacollectionbjm');

    $this->assertSame('waiting_credentials', $gita['state']);
    $this->assertFalse($gita['checks']['credentials']);
    $this->assertSame(['SHOPEE_GITA_PARTNER_ID', 'SHOPEE_GITA_PARTNER_KEY', 'SHOPEE_GITA_REDIRECT_URL'], $gita['required_env']);
    $this->assertStringNotContainsString('never-leak', $response->getContent());
}
```

Additional tests must prove:

- complete credentials without active token produce `authorization_required`;
- expired active token produces `token_expired`;
- valid token/shop without listings produces `mapping_required`;
- valid token/shop plus an active listing produces `ready`;
- `disabled` has highest priority;
- a Gita failure does not change another account's state.

- [ ] **Step 2: Run the readiness tests and verify RED**

Run: `cd backend; php artisan test tests/Feature/MarketplaceAccountReadinessTest.php`

Expected: FAIL because the readiness service and `data.accounts` do not exist.

- [ ] **Step 3: Implement readiness in deterministic priority order**

Use this state priority:

```php
if (! $enabled) return 'disabled';
if (! $credentials) return 'waiting_credentials';
if (! $activeToken || ! $shopIdentity) return 'authorization_required';
if (! $tokenUsable) return 'token_expired';
if ($mappedSkus === 0) return 'mapping_required';
return 'ready';
```

For Shopee, locate the newest active token by `account_key`; require a positive `shop_id`. For TikTok, locate the newest active token by `account_key`, require an authorized shop/cipher, and require a non-empty warehouse ID from account configuration or cached active listings. Treat unparsable expiry as unusable only when an expiry value exists; preserve compatibility with older non-expiring test fixtures.

Catch credential-resolution exceptions only to set the sanitized credential check; never return exception traces or context arrays.

- [ ] **Step 4: Add readiness to the existing dashboard service**

Preserve the existing two-argument `MarketplaceSyncService` constructor because focused tests instantiate it directly. Resolve the readiness service only in `dashboard()` and add:

```php
'accounts' => app(MarketplaceAccountReadinessService::class)->all(),
```

Keep all current dashboard fields unchanged.

- [ ] **Step 5: Run focused and dashboard regressions**

Run: `cd backend; php artisan test tests/Feature/MarketplaceAccountReadinessTest.php tests/Feature/MarketplaceTokenSyncTest.php`

Expected: PASS.

- [ ] **Step 6: Commit Task 4**

```powershell
git add backend/app/Services/MarketplaceAccountReadinessService.php backend/app/Services/MarketplaceSyncService.php backend/tests/Feature/MarketplaceAccountReadinessTest.php
git commit -m "feat: report marketplace account readiness"
```

---

### Task 5: Automatic Synchronization Account Cards

**Files:**

- Create: `frontend/src/pages/marketplaceAccountReadinessState.js`
- Create: `frontend/tests/marketplaceAccountReadinessState.test.js`
- Modify: `frontend/src/pages/MarketplaceAutoSync.vue`

**Interfaces:**

- Consumes: `dashboard.accounts` from `GET /api/marketplace/auto-sync`.
- Produces: `normalizeMarketplaceAccounts(rows): AccountCard[]`
- Produces: `canAuthorizeAccount(account): boolean`
- Does not add live polling or stock-mutation actions.

- [ ] **Step 1: Write failing pure-state tests**

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  canAuthorizeAccount,
  normalizeMarketplaceAccounts
} from '../src/pages/marketplaceAccountReadinessState.js'

test('normalizes all three account readiness cards in server order', () => {
  const cards = normalizeMarketplaceAccounts([
    { key: 'shopee-agnishopbjm', name: 'Shopee AgniShopBJM', channel: 'shopee', state: 'ready', checks: {}, mapped_skus: 10 },
    { key: 'tiktok-agnishopbjm', name: 'TikTok AgniShopBJM', channel: 'tiktok', state: 'ready', checks: {}, mapped_skus: 9 },
    { key: 'shopee-gitacollectionbjm', name: 'Shopee GitaCollectionBJM', channel: 'shopee', state: 'waiting_credentials', checks: {}, mapped_skus: 0 }
  ])

  assert.deepEqual(cards.map((card) => card.key), [
    'shopee-agnishopbjm',
    'tiktok-agnishopbjm',
    'shopee-gitacollectionbjm'
  ])
  assert.equal(cards[2].stateLabel, 'Menunggu kredensial')
})

test('authorization is allowed only after credentials are ready', () => {
  assert.equal(canAuthorizeAccount({ state: 'waiting_credentials', checks: { credentials: false } }), false)
  assert.equal(canAuthorizeAccount({ state: 'authorization_required', checks: { credentials: true } }), true)
  assert.equal(canAuthorizeAccount({ state: 'token_expired', checks: { credentials: true } }), true)
})
```

- [ ] **Step 2: Run frontend tests and verify RED**

Run: `cd frontend; npm test -- marketplaceAccountReadinessState.test.js`

Expected: FAIL because the module does not exist.

- [ ] **Step 3: Implement the pure state module**

Map backend states to Indonesian labels and badge classes. Default missing arrays/objects safely. `canAuthorizeAccount` must require `checks.credentials === true` and a Shopee channel; it must return false for `ready`, `mapping_required`, `waiting_credentials`, and `disabled`.

- [ ] **Step 4: Render the flow summary and account cards**

Add the account section immediately after the notice/alerts and before runtime/STB controls. Show:

- `Stock Master` as the source node;
- one target node per account;
- account name and channel;
- readiness badge and sanitized message;
- credential, token/shop, mapping check labels;
- mapped SKU count;
- required `.env` names only when state is `waiting_credentials`;
- an authorization button only when `canAuthorizeAccount(account)` is true.

Use the existing `omnichannelService.runTokenAction(account.connect_action)` and existing redirect behavior. Do not render connection tests, manual account polling, manual stock push, or retry buttons until their backend account-aware implementations exist.

- [ ] **Step 5: Run frontend tests and production build**

Run: `cd frontend; npm test`

Expected: all tests PASS.

Run: `cd frontend; npm run build`

Expected: Vite build succeeds with no compile error.

- [ ] **Step 6: Commit Task 5**

```powershell
git add frontend/src/pages/marketplaceAccountReadinessState.js frontend/tests/marketplaceAccountReadinessState.test.js frontend/src/pages/MarketplaceAutoSync.vue
git commit -m "feat: show marketplace account readiness"
```

---

### Task 6: Environment and STB Activation Documentation

**Files:**

- Modify: `backend/.env.example`
- Modify: `docs/STB_CONFIG_REFERENCE.md`
- Modify: `frontend/tests/marketplaceAccountReadinessState.test.js`

**Interfaces:**

- Documents the exact fields operators will populate after Shopee approves the application.
- Does not edit `backend/.env` or include any real credential value.

- [ ] **Step 1: Add a failing source-contract test for safe setup guidance**

Extend the frontend/source contract test or add a small repository-level Node test that reads `backend/.env.example` and `docs/STB_CONFIG_REFERENCE.md`, then asserts the presence of:

```text
SHOPEE_GITA_ENABLED
SHOPEE_GITA_USE_PRIMARY_APP
SHOPEE_GITA_PARTNER_ID
SHOPEE_GITA_PARTNER_KEY
SHOPEE_GITA_HOST
SHOPEE_GITA_REDIRECT_URL
TIKTOK_APP_KEY
TIKTOK_APP_SECRET
TIKTOK_AUTH_HOST
TIKTOK_API_HOST
TIKTOK_REDIRECT_URL
```

The test must also reject example values matching `secret`, a long token-like value, or a real Partner Key assignment.

- [ ] **Step 2: Run the source-contract test and verify RED**

Run: `cd frontend; npm test -- marketplaceAccountReadinessState.test.js`

Expected: FAIL because the Gita and missing TikTok example keys are not all documented.

- [ ] **Step 3: Update `.env.example` with blank values and safe defaults**

Add:

```dotenv
SHOPEE_GITA_ENABLED=true
SHOPEE_GITA_USE_PRIMARY_APP=false
SHOPEE_GITA_PARTNER_ID=
SHOPEE_GITA_PARTNER_KEY=
SHOPEE_GITA_HOST=https://partner.shopeemobile.com
SHOPEE_GITA_REDIRECT_URL=

TIKTOK_APP_KEY=
TIKTOK_APP_SECRET=
TIKTOK_AUTH_HOST=https://auth.tiktok-shops.com
TIKTOK_API_HOST=https://open-api.tiktokglobalshop.com
TIKTOK_REDIRECT_URL=
```

- [ ] **Step 4: Document the PC/STB activation sequence**

Document two mutually exclusive Shopee modes:

1. Dedicated Gita app: fill all `SHOPEE_GITA_*` credential/callback fields and keep `SHOPEE_GITA_USE_PRIMARY_APP=false`.
2. Shared approved app: set `SHOPEE_GITA_USE_PRIMARY_APP=true` and leave Gita credential fields blank.

Require `php artisan optimize:clear`, authorization of the exact Gita shop, token-sync confirmation, mapping readiness, and a supervised test before any future live scheduling is enabled. Explicitly say that this foundation release does not add Gita order polling or stock push.

- [ ] **Step 5: Run all phase verification**

Run: `cd backend; php artisan test`

Expected: full backend suite PASS.

Run: `cd frontend; npm test`

Expected: full frontend suite PASS.

Run: `cd frontend; npm run build`

Expected: production build succeeds.

Run from repository root: `git diff --check`

Expected: no whitespace errors.

- [ ] **Step 6: Publish built frontend assets for the Laravel host**

After the build succeeds, copy `frontend/dist/index.html` to `backend/public/index.html` and copy all files under `frontend/dist/assets` to `backend/public/assets` using the established Windows-safe publish procedure. Verify the Laravel host returns HTTP 200 and references the new hashed bundle; verify the served bundle contains `Shopee GitaCollectionBJM` and `Menunggu kredensial`.

- [ ] **Step 7: Commit Task 6**

```powershell
git add backend/.env.example docs/STB_CONFIG_REFERENCE.md frontend/tests/marketplaceAccountReadinessState.test.js backend/public/index.html backend/public/assets
git commit -m "docs: prepare Gitashop marketplace credentials"
```

## Deferred Follow-Up Plans

This foundation deliberately leaves live behavior unchanged. After it is verified, create separate implementation plans for:

1. Account-aware inventory events and idempotent order application.
2. Per-account absolute-stock delivery, retry, and reconciliation.
3. Multi-account scheduler/STB polling and supervised activation controls.

Each follow-up must begin with failing tests and must preserve the readiness gate established here.
