<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class ShopeeTokenRefreshLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_refresh_refuses_to_run_when_the_account_refresh_is_locked(): void
    {
        $lock = Cache::lock('shopee-token-refresh:shopee-agnishopbjm', 60);
        $this->assertTrue($lock->get());

        try {
            $controller = app(OmnichannelController::class);
            $method = new ReflectionMethod($controller, 'refreshShopeeToken');
            $result = $method->invoke($controller, [
                'key' => 'shopee-agnishopbjm',
                'name' => 'Shopee AgniShopBJM',
            ]);

            $this->assertSame('error', $result['status']);
            $this->assertSame('Refresh token Shopee AgniShopBJM sedang diproses. Tunggu lalu coba lagi.', $result['message']);
        } finally {
            $lock->release();
        }
    }
}
