<?php

namespace Tests\Feature;

use App\Http\Controllers\OmnichannelController;
use App\Services\MarketplaceOperationLeaseService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\StbRuntimeService;
use App\Services\StbSyncWorkerService;
use App\Services\StockConsistencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MarketplaceOperationLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_upload_lease_blocks_a_second_marketplace_operation(): void
    {
        $leases = app(MarketplaceOperationLeaseService::class);

        $upload = $leases->acquire('gitashop_mass_upload', 120);
        $stb = $leases->acquire('stb_marketplace_sync', 120);

        $this->assertTrue($upload['acquired']);
        $this->assertFalse($stb['acquired']);
        $this->assertSame('gitashop_mass_upload', $stb['operation']);
        $this->assertNotEmpty($upload['token']);
    }

    public function test_expired_lease_is_reclaimed_and_release_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-12 10:00:00');
        $leases = app(MarketplaceOperationLeaseService::class);
        $first = $leases->acquire('gitashop_mass_upload', 30);

        Carbon::setTestNow('2026-08-12 10:01:00');
        $second = $leases->acquire('stb_marketplace_sync', 30);

        $this->assertTrue($second['acquired']);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertTrue($leases->release($second['token']));
        $this->assertTrue($leases->release($second['token']));
    }

    public function test_public_stb_status_exposes_sanitized_marketplace_operation_without_lease_token(): void
    {
        $lease = app(MarketplaceOperationLeaseService::class)->acquire('gitashop_mass_upload', 120);

        $response = $this->getJson('/api/runtime/stb-status')
            ->assertOk()
            ->assertJsonPath('marketplace_operation.active', true)
            ->assertJsonPath('marketplace_operation.operation', 'gitashop_mass_upload')
            ->assertJsonPath('marketplace_operation.locked_until_at', $lease['locked_until_at']);

        $this->assertArrayNotHasKey('token', $response->json('marketplace_operation'));
        $this->assertStringNotContainsString($lease['token'], $response->getContent());
    }

    public function test_marketplace_operation_control_endpoints_require_dedicated_token(): void
    {
        Config::set('shopee_mass_upload.stb_control_token', 'mass-upload-control-token');

        $this->postJson('/api/runtime/marketplace-operation/acquire', [
            'operation' => 'gitashop_mass_upload',
            'seconds' => 120,
        ])->assertUnauthorized();

        $response = $this->withToken('mass-upload-control-token')
            ->postJson('/api/runtime/marketplace-operation/acquire', [
                'operation' => 'gitashop_mass_upload',
                'seconds' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.acquired', true)
            ->assertJsonPath('data.operation', 'gitashop_mass_upload');

        $token = $response->json('data.token');

        $this->assertNotEmpty($token);

        $this->withToken('mass-upload-control-token')
            ->postJson('/api/runtime/marketplace-operation/release', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.released', true);
    }

    public function test_remote_stb_status_proxy_removes_any_lease_token(): void
    {
        Config::set('stb.status_url', 'https://stb.test/api/runtime/stb-status');
        Http::fake([
            'https://stb.test/*' => Http::response([
                'status' => 'ok',
                'marketplace_operation' => [
                    'active' => true,
                    'operation' => 'gitashop_mass_upload',
                    'locked_until_at' => '2026-08-12 10:10:00',
                    'token' => 'remote-lease-token',
                ],
            ]),
        ]);

        $response = $this->getJson('/api/runtime/stb-status')
            ->assertOk()
            ->assertJsonPath('marketplace_operation.operation', 'gitashop_mass_upload');

        $this->assertArrayNotHasKey('token', $response->json('marketplace_operation'));
        $this->assertStringNotContainsString('remote-lease-token', $response->getContent());
    }

    public function test_stb_sync_releases_its_lease_when_finishing_throws(): void
    {
        $orders = Mockery::mock(MarketplaceOrderSyncService::class);
        $orders->shouldReceive('pollShopeeReadyOrders')->once()->andReturn(['status' => 'ok', 'processed' => 0, 'failed' => 0]);
        $orders->shouldReceive('pollTiktokUpdatedOrders')->once()->andReturn(['status' => 'ok', 'processed' => 0, 'failed' => 0]);
        $orders->shouldReceive('processPendingProductCacheRefreshes')->once()->andReturn(['status' => 'ok', 'processed' => 0, 'failed' => 0]);

        $omnichannel = Mockery::mock(OmnichannelController::class);
        $omnichannel->shouldReceive('autoRefreshMarketplaceTokens')->once();
        $this->app->instance(OmnichannelController::class, $omnichannel);

        $runtime = Mockery::mock(StbRuntimeService::class);
        $runtime->shouldReceive('heartbeat')->once();
        $runtime->shouldReceive('logSync')->once()->andThrow(new \RuntimeException('logging failed'));

        $leases = app(MarketplaceOperationLeaseService::class);
        $worker = new StbSyncWorkerService(
            $orders,
            Mockery::mock(StockConsistencyService::class),
            $runtime,
            $leases,
        );

        try {
            $worker->syncOrders();
            $this->fail('Expected finish failure to bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('logging failed', $exception->getMessage());
        }

        $this->assertFalse($leases->status()['active']);
    }

    public function test_stb_syncs_skip_safely_while_gitashop_upload_lease_is_active(): void
    {
        app(MarketplaceOperationLeaseService::class)->acquire('gitashop_mass_upload', 120);

        $orders = Mockery::mock(MarketplaceOrderSyncService::class);
        $orders->shouldNotReceive('pollShopeeReadyOrders');
        $orders->shouldNotReceive('pollTiktokUpdatedOrders');
        $orders->shouldNotReceive('processPendingProductCacheRefreshes');

        $stock = Mockery::mock(StockConsistencyService::class);
        $omnichannel = Mockery::mock(OmnichannelController::class);
        $omnichannel->shouldNotReceive('autoRefreshMarketplaceTokens');
        $omnichannel->shouldNotReceive('syncMarketplaceCachesForSkuMapping');
        $this->app->instance(OmnichannelController::class, $omnichannel);

        $runtime = Mockery::mock(StbRuntimeService::class);
        $runtime->shouldReceive('heartbeat')->twice();
        $runtime->shouldReceive('logSync')->twice()->withArgs(function (string $source, string $target, string $status, string $message): bool {
            return in_array($source, ['stb_order_sync', 'stb_marketplace_lite'], true)
                && in_array($target, ['marketplace_orders', 'marketplace_cache'], true)
                && $status === 'skipped'
                && str_contains($message, 'operasi marketplace terlindungi');
        });
        $runtime->shouldReceive('markSchedulerTick')->twice();
        $runtime->shouldReceive('logEvent')->twice();

        $worker = new StbSyncWorkerService(
            $orders,
            $stock,
            $runtime,
            app(MarketplaceOperationLeaseService::class),
        );

        $orderResult = $worker->syncOrders();
        $marketplaceResult = $worker->syncMarketplaceLite();

        $this->assertSame('skipped', $orderResult['status']);
        $this->assertSame('gitashop_mass_upload', $orderResult['context']['active_operation']);
        $this->assertSame('skipped', $marketplaceResult['status']);
        $this->assertSame('gitashop_mass_upload', $marketplaceResult['context']['active_operation']);
    }
}
