<?php

use App\Http\Controllers\OmnichannelController;
use App\Services\MarketplaceOrderSyncService;
use App\Services\StockConsistencyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:agnishop', function (): void {
    $this->info('AgniShop Banjarmasin API');
});

Artisan::command('sku-mapping:sync-marketplaces', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(OmnichannelController::class)->syncMarketplaceCachesForSkuMapping();

    $this->info($result['message'] ?? 'Sync marketplace selesai.');
    $this->line('Shopee: '.($result['shopee']['message'] ?? $result['shopee']['status'] ?? '-'));
    $this->line('TikTok: '.($result['tiktok']['message'] ?? $result['tiktok']['status'] ?? '-'));
    $this->line('Auto-hidden stock master: '.($result['auto_hidden_inactive_stock_master'] ?? 0));

    return ($result['status'] ?? 'ok') === 'partial_error' ? 1 : 0;
});

Schedule::command('sku-mapping:sync-marketplaces')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Artisan::command('marketplace:refresh-tokens {--force}', function (): int {
    $result = app(OmnichannelController::class)->autoRefreshMarketplaceTokens((bool) $this->option('force'));

    $this->info($result['message'] ?? 'Auto refresh token selesai.');
    foreach ($result['items'] ?? [] as $item) {
        $this->line(sprintf(
            '%s | %s | %s',
            $item['account_name'] ?? $item['account_key'] ?? '-',
            $item['status'] ?? '-',
            $item['message'] ?? $item['error'] ?? '-'
        ));
    }

    return ($result['status'] ?? 'ok') === 'warning' ? 1 : 0;
});

Schedule::command('marketplace:refresh-tokens')
    ->everyTenMinutes()
    ->withoutOverlapping();

Artisan::command('sync:safety-check', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(StockConsistencyService::class)->run();

    $this->info($result['message'] ?? 'Safety check selesai.');
    $this->line('Total checked: '.($result['total_checked'] ?? 0));
    $this->line('Total corrected: '.($result['total_corrected'] ?? 0));

    return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
});

Schedule::command('sync:safety-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Artisan::command('sync:shopee-orders {--hours=24}', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(MarketplaceOrderSyncService::class)->pollShopeeReadyOrders((int) $this->option('hours'));

    $this->info($result['message'] ?? 'Polling order Shopee selesai.');
    foreach ($result['messages'] ?? [] as $message) {
        $this->line($message);
    }

    return ($result['status'] ?? 'success') === 'success' ? 0 : 1;
});

Schedule::command('sync:shopee-orders --hours=24')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('sync:tiktok-orders {--hours=24}', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(MarketplaceOrderSyncService::class)->pollTiktokUpdatedOrders((int) $this->option('hours'));

    $this->info($result['message'] ?? 'Polling order TikTok selesai.');
    foreach ($result['messages'] ?? [] as $message) {
        $this->line($message);
    }

    return ($result['status'] ?? 'success') === 'success' ? 0 : 1;
});

Schedule::command('sync:tiktok-orders --hours=24')
    ->everyFiveMinutes()
    ->withoutOverlapping();
