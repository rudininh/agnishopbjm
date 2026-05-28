<?php

use App\Http\Controllers\OmnichannelController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:agnishop', function (): void {
    $this->info('AgniShop Banjarmasin API');
});

Artisan::command('sku-mapping:sync-marketplaces', function (): int {
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
