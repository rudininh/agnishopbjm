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

    public function test_unknown_account_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MarketplaceAccountRegistry::class)->account('unknown-marketplace-account');
    }

    public function test_channel_mismatch_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MarketplaceAccountRegistry::class)->shopeeContext('tiktok-agnishopbjm');
    }

    public function test_tiktok_context_returns_complete_tiktok_credentials(): void
    {
        Config::set('marketplace_accounts.accounts.tiktok-agnishopbjm.credentials', [
            'app_key' => 'tiktok-key',
            'app_secret' => 'tiktok-secret',
            'auth_host' => 'https://auth.tiktok-shops.com',
            'api_host' => 'https://open-api.tiktokglobalshop.com',
            'redirect_url' => 'https://example.test/api/tiktok/callback',
            'warehouse_id' => 'warehouse-1',
        ]);

        $context = app(MarketplaceAccountRegistry::class)->tiktokContext('tiktok-agnishopbjm');

        $this->assertSame('tiktok-key', $context['app_key']);
        $this->assertSame('warehouse-1', $context['warehouse_id']);
    }

    public function test_incomplete_tiktok_credentials_fail_without_leaking_the_secret(): void
    {
        Config::set('marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.app_secret', 'do-not-leak');
        Config::set('marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.warehouse_id', '');

        try {
            app(MarketplaceAccountRegistry::class)->tiktokContext('tiktok-agnishopbjm');
            $this->fail('Expected incomplete configuration to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Konfigurasi TikTok AgniShopBJM belum lengkap.', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-leak', $exception->getMessage());
        }
    }
}
