<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_credentials_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $account = MarketplaceAccount::create([
            'account_key' => 'shopee-toko-baru',
            'name' => 'Shopee Toko Baru',
            'channel' => 'shopee',
            'enabled' => true,
            'settings' => ['host' => 'https://partner.shopeemobile.com'],
            'credentials' => [
                'partner_id' => 123456,
                'partner_key' => 'partner-key-never-return',
                'redirect_url' => 'https://gitashopbjm.vercel.app/api/callback',
            ],
        ]);

        $storedCredentials = DB::table('marketplace_accounts')->where('id', $account->id)->value('credentials');
        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('partner-key-never-return', $storedCredentials);
        $this->assertSame('partner-key-never-return', $account->fresh()->credentials['partner_key']);
        $this->assertStringNotContainsString('partner-key-never-return', json_encode($account->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_marketplace_account_key_is_unique(): void
    {
        MarketplaceAccount::create([
            'account_key' => 'shopee-toko-baru',
            'name' => 'Shopee Toko Baru',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [],
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        MarketplaceAccount::create([
            'account_key' => 'shopee-toko-baru',
            'name' => 'Shopee Toko Duplikat',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [],
        ]);
    }
    public function test_database_account_is_added_to_public_registry_without_credentials(): void
    {
        MarketplaceAccount::create([
            'account_key' => 'shopee-toko-baru',
            'name' => 'Shopee Toko Baru',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [
                'partner_id' => 123456,
                'partner_key' => 'secret-not-public',
            ],
        ]);

        $account = collect(app(\App\Services\MarketplaceAccountRegistry::class)->publicAccounts())
            ->firstWhere('key', 'shopee-toko-baru');

        $this->assertIsArray($account);
        $this->assertSame('Shopee Toko Baru', $account['name']);
        $this->assertTrue($account['credentials_configured']);
        $this->assertStringNotContainsString('secret-not-public', json_encode($account, JSON_THROW_ON_ERROR));
    }

    public function test_database_account_overrides_matching_config_context(): void
    {
        config([
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.name' => 'Config Name',
            'marketplace_accounts.accounts.shopee-gitacollectionbjm.use_primary_app' => false,
        ]);
        MarketplaceAccount::create([
            'account_key' => 'shopee-gitacollectionbjm',
            'name' => 'Database Gitashop',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [
                'partner_id' => 987654,
                'partner_key' => 'database-partner-key',
                'host' => 'https://partner.database.test',
                'redirect_url' => 'https://gitashopbjm.vercel.app/api/callback',
            ],
        ]);

        $registry = app(\App\Services\MarketplaceAccountRegistry::class);
        $context = $registry->shopeeContext('shopee-gitacollectionbjm');

        $this->assertSame(987654, $context['partner_id']);
        $this->assertSame('database-partner-key', $context['partner_key']);
        $this->assertSame('https://gitashopbjm.vercel.app/api/callback', $context['redirect_url']);
        $this->assertSame('Database Gitashop', $registry->account('shopee-gitacollectionbjm')['name']);
    }
    public function test_marketplace_account_catalog_is_public_and_does_not_expose_credentials(): void
    {
        $response = $this->getJson('/api/marketplace/accounts');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'accounts' => [[
                        'key',
                        'name',
                        'channel',
                        'enabled',
                        'credentials_configured',
                    ]],
                ],
            ])
            ->assertJsonMissingPath('data.accounts.0.credentials');
    }

    public function test_authenticated_user_can_create_account_without_secret_in_response(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/marketplace/accounts', [
                'account_key' => 'shopee-toko-baru',
                'name' => 'Shopee Toko Baru',
                'channel' => 'shopee',
                'enabled' => true,
                'credentials' => [
                    'partner_id' => 123456,
                    'partner_key' => 'create-secret-never-return',
                    'host' => 'https://partner.shopeemobile.com',
                    'redirect_url' => 'https://gitashopbjm.vercel.app/api/callback',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.account_key', 'shopee-toko-baru')
            ->assertJsonMissing(['partner_key' => 'create-secret-never-return']);
        $stored = MarketplaceAccount::where('account_key', 'shopee-toko-baru')->firstOrFail();
        $this->assertSame('create-secret-never-return', $stored->credentials['partner_key']);
        $this->assertStringNotContainsString('create-secret-never-return', $response->getContent());
    }

    public function test_account_update_keeps_existing_credentials_when_omitted(): void
    {
        MarketplaceAccount::create([
            'account_key' => 'shopee-toko-baru',
            'name' => 'Nama Lama',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [
                'partner_id' => 123456,
                'partner_key' => 'keep-secret-never-return',
            ],
        ]);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/marketplace/accounts/shopee-toko-baru', [
                'name' => 'Nama Baru',
                'enabled' => false,
            ]);

        $response->assertOk()->assertJsonPath('data.name', 'Nama Baru');
        $account = MarketplaceAccount::where('account_key', 'shopee-toko-baru')->firstOrFail();
        $this->assertFalse($account->enabled);
        $this->assertSame('keep-secret-never-return', $account->credentials['partner_key']);
        $this->assertStringNotContainsString('keep-secret-never-return', $response->getContent());
    }

    public function test_account_connection_test_returns_safe_incomplete_status(): void
    {
        MarketplaceAccount::create([
            'account_key' => 'shopee-incomplete',
            'name' => 'Shopee Incomplete',
            'channel' => 'shopee',
            'enabled' => true,
            'credentials' => [],
        ]);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/marketplace/accounts/shopee-incomplete/test');

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonMissingPath('data.credentials');
    }
}
