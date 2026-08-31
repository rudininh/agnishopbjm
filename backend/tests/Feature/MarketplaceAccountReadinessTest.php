<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceAccountReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stb.sync_worker' => true,
            'stb.features.stock_analysis' => false,
        ]);
    }

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

        $this->assertSame([
            'key',
            'name',
            'channel',
            'state',
            'message',
            'checks',
            'mapped_skus',
            'connect_action',
            'required_env',
        ], array_keys($gita));
        $this->assertSame('waiting_credentials', $gita['state']);
        $this->assertFalse($gita['checks']['credentials']);
        $this->assertSame(['SHOPEE_GITA_PARTNER_ID', 'SHOPEE_GITA_PARTNER_KEY', 'SHOPEE_GITA_REDIRECT_URL'], $gita['required_env']);
        $this->assertStringNotContainsString('never-leak', $response->getContent());
    }

    public function test_complete_credentials_without_active_token_require_authorization(): void
    {
        $this->configureShopeeAccount('shopee-gitacollectionbjm');

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('authorization_required', $account['state']);
        $this->assertTrue($account['checks']['credentials']);
        $this->assertFalse($account['checks']['active_token']);
    }

    public function test_gita_shared_app_readiness_reports_primary_environment_names(): void
    {
        $this->configureShopeeAccount('shopee-agnishopbjm');
        config([
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.enabled' => true,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app' => true,
        ]);

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('authorization_required', $account['state']);
        $this->assertTrue($account['checks']['credentials']);
        $this->assertSame([
            'SHOPEE_PARTNER_ID',
            'SHOPEE_PARTNER_KEY',
            'SHOPEE_REDIRECT_URL',
        ], $account['required_env']);
    }

    public function test_expired_active_token_is_reported_as_expired(): void
    {
        $this->configureShopeeAccount('shopee-gitacollectionbjm');
        $this->insertShopeeToken('shopee-gitacollectionbjm', now()->subMinute()->toDateTimeString());

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('token_expired', $account['state']);
        $this->assertTrue($account['checks']['active_token']);
        $this->assertTrue($account['checks']['shop_identity']);
        $this->assertFalse($account['checks']['token_usable']);
    }

    public function test_present_unparsable_token_expiry_is_unusable(): void
    {
        $this->configureShopeeAccount('shopee-gitacollectionbjm');
        $this->insertShopeeToken('shopee-gitacollectionbjm', 'not-a-date');

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('token_expired', $account['state']);
        $this->assertFalse($account['checks']['token_usable']);
    }

    public function test_legacy_token_without_expiry_requires_mapping(): void
    {
        $this->configureShopeeAccount('shopee-gitacollectionbjm');
        $this->insertShopeeToken('shopee-gitacollectionbjm');

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('mapping_required', $account['state']);
        $this->assertTrue($account['checks']['token_usable']);
        $this->assertSame(0, $account['mapped_skus']);
    }

    public function test_valid_token_and_exact_active_listing_are_ready(): void
    {
        $this->configureShopeeAccount('shopee-gitacollectionbjm');
        $this->insertShopeeToken('shopee-gitacollectionbjm', now()->addHour()->toDateTimeString());
        $this->insertListing('shopee-gitacollectionbjm', 11, true);
        $this->insertListing('shopee-gitacollectionbjm', 12, false);
        $this->insertListing('shopee-agnishopbjm', 13, true);

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('ready', $account['state']);
        $this->assertTrue($account['checks']['mappings']);
        $this->assertSame(1, $account['mapped_skus']);
    }

    public function test_disabled_has_priority_over_missing_credentials_and_expired_token(): void
    {
        config([
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.enabled' => false,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app' => false,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_id' => 0,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_key' => '',
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.redirect_url' => '',
        ]);
        $this->insertShopeeToken('shopee-gitacollectionbjm', now()->subMinute()->toDateTimeString());

        $account = $this->accountFromDashboard('shopee-gitacollectionbjm');

        $this->assertSame('disabled', $account['state']);
    }

    public function test_gita_failure_does_not_change_ready_primary_account(): void
    {
        $this->configureShopeeAccount('shopee-agnishopbjm');
        config([
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.enabled' => true,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app' => false,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_id' => 0,
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.partner_key' => 'gita-secret-never-leak',
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.credentials.redirect_url' => '',
        ]);
        $this->insertShopeeToken('shopee-agnishopbjm', now()->addHour()->toDateTimeString());
        $this->insertListing('shopee-agnishopbjm', 21, true);

        $response = $this->getJson('/api/marketplace/auto-sync')->assertOk();
        $accounts = collect($response->json('data.accounts'));

        $this->assertSame('ready', $accounts->firstWhere('key', 'shopee-agnishopbjm')['state']);
        $this->assertSame('waiting_credentials', $accounts->firstWhere('key', 'shopee-gitacollectionbjm')['state']);
        $this->assertStringNotContainsString('gita-secret-never-leak', $response->getContent());
    }

    public function test_tiktok_uses_its_token_account_key_and_cached_listing_warehouse(): void
    {
        $this->createTikTokAuthTables();
        config([
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.app_key' => 'test-app-key',
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.app_secret' => 'test-app-secret',
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.auth_host' => 'https://auth.tiktok.test',
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.api_host' => 'https://api.tiktok.test',
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.redirect_url' => 'https://app.test/api/tiktok/callback',
            'marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.warehouse_id' => '',
        ]);
        DB::table('tiktok_tokens')->insert([
            [
                'account_key' => 'some-other-account',
                'shop_id' => 'wrong-shop',
                'access_token' => 'wrong-token-never-leak',
                'access_token_expire_at' => now()->addHour(),
                'is_active' => true,
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ],
            [
                'account_key' => 'tiktok-agnishopbjm',
                'shop_id' => 'shop-1',
                'access_token' => 'right-token-never-leak',
                'access_token_expire_at' => now()->addHour(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('tiktok_shops')->insert([
            'id' => 'shop-row-1',
            'shop_id' => 'shop-1',
            'cipher' => 'shop-cipher-never-leak',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertListing('tiktok-agnishopbjm', 31, true, 'warehouse-from-listing');

        $response = $this->getJson('/api/marketplace/auto-sync')->assertOk();
        $account = collect($response->json('data.accounts'))->firstWhere('key', 'tiktok-agnishopbjm');

        $this->assertSame('ready', $account['state']);
        $this->assertTrue($account['checks']['warehouse']);
        $this->assertStringNotContainsString('wrong-token-never-leak', $response->getContent());
        $this->assertStringNotContainsString('right-token-never-leak', $response->getContent());
        $this->assertStringNotContainsString('shop-cipher-never-leak', $response->getContent());
    }

    private function accountFromDashboard(string $accountKey): array
    {
        $response = $this->getJson('/api/marketplace/auto-sync')->assertOk();
        $account = collect($response->json('data.accounts'))->firstWhere('key', $accountKey);

        $this->assertIsArray($account);

        return $account;
    }

    private function configureShopeeAccount(string $accountKey): void
    {
        config([
            "marketplace_accounts.accounts.{$accountKey}.enabled" => true,
            "marketplace_accounts.accounts.{$accountKey}.use_primary_app" => false,
            "marketplace_accounts.accounts.{$accountKey}.credentials.partner_id" => 12345,
            "marketplace_accounts.accounts.{$accountKey}.credentials.partner_key" => 'test-partner-key',
            "marketplace_accounts.accounts.{$accountKey}.credentials.host" => 'https://partner.test',
            "marketplace_accounts.accounts.{$accountKey}.credentials.redirect_url" => 'https://app.test/api/shopee/callback',
        ]);
    }

    private function insertShopeeToken(string $accountKey, ?string $expiry = null): void
    {
        DB::table('shopee_tokens')->insert([
            'account_key' => $accountKey,
            'account_name' => $accountKey,
            'shop_id' => 123456,
            'access_token' => 'access-token-never-return',
            'refresh_token' => 'refresh-token-never-return',
            'access_token_expire_at' => $expiry,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertListing(string $accountKey, int $stockMasterId, bool $active, ?string $warehouseId = null): void
    {
        $channel = str_starts_with($accountKey, 'tiktok-') ? 'tiktok' : 'shopee';
        $remoteProductId = 'product-'.$stockMasterId;
        $remoteVariantId = 'variant-'.$stockMasterId;

        DB::table('marketplace_listings')->insert([
            'stock_master_id' => $stockMasterId,
            'account_key' => $accountKey,
            'channel' => $channel,
            'remote_product_id' => $remoteProductId,
            'remote_variant_id' => $remoteVariantId,
            'remote_identity_hash' => hash('sha256', json_encode([$channel, $remoteProductId, $remoteVariantId], JSON_THROW_ON_ERROR)),
            'seller_sku' => 'INT-'.$stockMasterId,
            'warehouse_id' => $warehouseId,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTikTokAuthTables(): void
    {
        Schema::create('tiktok_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->nullable();
            $table->string('shop_id')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expire_at')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tiktok_shops', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('shop_id')->nullable();
            $table->text('cipher')->nullable();
            $table->text('shop_cipher')->nullable();
            $table->timestamps();
        });
    }
}
