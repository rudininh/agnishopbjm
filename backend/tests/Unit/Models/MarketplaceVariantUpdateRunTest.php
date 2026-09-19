<?php

namespace Tests\Unit\Models;

use App\Models\MarketplaceVariantUpdateResult;
use App\Models\MarketplaceVariantUpdateRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceVariantUpdateRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_assigns_uuid_casts_requested_accounts_and_rejects_duplicate_account_results(): void
    {
        $run = MarketplaceVariantUpdateRun::query()->create([
            'stock_master_id' => 10,
            'requested_stock_qty' => 12,
            'requested_accounts' => ['shopee-agnishopbjm'],
            'status' => 'running',
        ]);

        $firstResult = MarketplaceVariantUpdateResult::query()->create([
            'run_id' => $run->id,
            'marketplace_listing_id' => 44,
            'account_key' => 'shopee-agnishopbjm',
            'channel' => 'shopee',
            'status' => 'pending',
            'idempotency_key' => hash('sha256', 'first-result'),
            'requested_stock_qty' => 12,
            'requested_price' => '125000.00',
            'response_summary' => ['request_id' => 'safe-id'],
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $run->id);
        $this->assertSame(['shopee-agnishopbjm'], $run->requested_accounts);
        $this->assertSame('125000.00', $firstResult->requested_price);
        $this->assertSame(['request_id' => 'safe-id'], $firstResult->response_summary);
        $this->assertTrue($run->results()->whereKey($firstResult)->exists());

        $this->expectException(QueryException::class);

        MarketplaceVariantUpdateResult::query()->create([
            'run_id' => $run->id,
            'marketplace_listing_id' => 45,
            'account_key' => 'shopee-agnishopbjm',
            'channel' => 'shopee',
            'status' => 'pending',
            'idempotency_key' => hash('sha256', 'duplicate-result'),
            'requested_stock_qty' => 12,
        ]);
    }
}
