<?php

namespace App\Services;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StbSyncWorkerService
{
    public function __construct(
        private readonly MarketplaceOrderSyncService $orderSyncService,
        private readonly StockConsistencyService $stockConsistencyService,
        private readonly StbRuntimeService $runtime,
    ) {
    }

    public function heartbeat(string $source = 'artisan'): array
    {
        return $this->runtime->heartbeat($source, true);
    }

    public function syncOrders(int $hours = 24): array
    {
        $this->runtime->heartbeat('agnishop:sync-orders', true);

        if (! (bool) config('stb.features.order_sync', true)) {
            return $this->finish('stb_order_sync', 'marketplace_orders', 'skipped', 'Order sync STB disabled dari environment.', []);
        }

        $hours = max(1, min(72, $hours));
        $this->refreshTokens();

        $shopee = $this->retry('poll_shopee_orders', fn (): array => $this->orderSyncService->pollShopeeReadyOrders($hours));
        $tiktok = $this->retry('poll_tiktok_orders', fn (): array => $this->orderSyncService->pollTiktokUpdatedOrders($hours));
        $refresh = $this->retry('pending_product_refresh', fn (): array => $this->orderSyncService->processPendingProductCacheRefreshes(
            (int) config('stb.worker.order_product_refresh_limit', 10)
        ));

        $failed = (int) ($shopee['failed'] ?? 0)
            + (int) ($tiktok['failed'] ?? 0)
            + (int) ($refresh['failed'] ?? 0);
        $status = $this->resultStatus([$shopee, $tiktok, $refresh], $failed);
        $message = sprintf(
            'STB order sync selesai. Shopee baru=%s, TikTok baru=%s, refresh=%s, gagal=%s.',
            (int) ($shopee['processed'] ?? 0),
            (int) ($tiktok['processed'] ?? 0),
            (int) ($refresh['processed'] ?? 0),
            $failed
        );

        return $this->finish('stb_order_sync', 'marketplace_orders', $status, $message, [
            'hours' => $hours,
            'shopee' => $this->compactResult($shopee),
            'tiktok' => $this->compactResult($tiktok),
            'refresh' => $this->compactResult($refresh),
        ]);
    }

    public function syncMarketplaceLite(): array
    {
        $this->runtime->heartbeat('agnishop:sync-marketplace-lite', true);

        if (! (bool) config('stb.features.marketplace_sync', true)) {
            return $this->finish('stb_marketplace_lite', 'marketplace_cache', 'skipped', 'Marketplace sync lite STB disabled dari environment.', []);
        }

        $this->refreshTokens();
        $result = $this->retry('sync_marketplace_lite', function (): array {
            return app(OmnichannelController::class)->syncMarketplaceCachesForSkuMapping();
        });

        $status = in_array(($result['status'] ?? ''), ['ok', 'success'], true) ? 'success' : 'warning';
        $message = sprintf(
            'STB marketplace lite selesai. Shopee=%s TikTok=%s.',
            $result['shopee']['message'] ?? $result['shopee']['status'] ?? '-',
            $result['tiktok']['message'] ?? $result['tiktok']['status'] ?? '-'
        );

        return $this->finish('stb_marketplace_lite', 'marketplace_cache', $status, $message, $this->compactResult($result));
    }

    public function safetyCheckLite(): array
    {
        $this->runtime->heartbeat('agnishop:safety-check-lite', true);

        if ((bool) config('stb.features.stock_analysis', false)) {
            $this->refreshTokens();
            $result = $this->retry('stock_consistency_safety_check', fn (): array => $this->stockConsistencyService->run());
            $status = ($result['status'] ?? '') === 'ok' ? 'success' : 'warning';

            return $this->finish('stb_safety_check_lite', 'stock_consistency', $status, $result['message'] ?? 'Safety check STB selesai.', $this->compactResult($result));
        }

        $summary = $this->lightSafetySummary();
        $status = ((int) ($summary['errors_last_24h'] ?? 0)) > 0 ? 'warning' : 'success';
        $message = sprintf(
            'STB safety check lite selesai. Error 24 jam=%s, skipped 24 jam=%s.',
            (int) ($summary['errors_last_24h'] ?? 0),
            (int) ($summary['skipped_last_24h'] ?? 0)
        );

        return $this->finish('stb_safety_check_lite', 'runtime', $status, $message, $summary);
    }

    public function runtimeStatus(): array
    {
        return $this->runtime->status();
    }

    private function refreshTokens(): void
    {
        try {
            app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        } catch (\Throwable $exception) {
            report($exception);
            $this->runtime->logSync('stb_token_refresh', 'marketplace_tokens', 'error', 'Auto refresh token STB gagal: '.$exception->getMessage());
        }
    }

    private function retry(string $name, callable $callback): array
    {
        $attempts = (int) config('stb.worker.retry_attempts', 2);
        $sleep = (int) config('stb.worker.retry_sleep_seconds', 3);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $callback();
                if (! is_array($result)) {
                    return ['status' => 'error', 'message' => $name.' tidak mengembalikan array.'];
                }

                $last = $result;
                if (! in_array(($result['status'] ?? ''), ['error', 'warning', 'partial_error'], true)) {
                    return $result;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $last = [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                    'attempt' => $attempt,
                ];
            }

            if ($attempt < $attempts && $sleep > 0) {
                sleep($sleep);
            }
        }

        return is_array($last) ? $last : ['status' => 'error', 'message' => $name.' gagal dijalankan.'];
    }

    private function lightSafetySummary(): array
    {
        if (! $this->hasTable('marketplace_sync_logs')) {
            return [
                'status' => 'warning',
                'message' => 'Tabel marketplace_sync_logs belum tersedia.',
                'errors_last_24h' => 0,
                'skipped_last_24h' => 0,
                'last_order_sync_at' => null,
            ];
        }

        $sources = ['shopee_order', 'shopee_stock_refresh', 'tiktok_order', 'stb_order_sync'];
        $since = now()->subDay();

        return [
            'errors_last_24h' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', $sources)
                ->where('status', 'error')
                ->where('created_at', '>=', $since)
                ->count(),
            'skipped_last_24h' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', $sources)
                ->where('status', 'skipped')
                ->where('created_at', '>=', $since)
                ->count(),
            'last_order_sync_at' => DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', $sources)
                ->max('created_at'),
            'last_error' => DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', $sources)
                ->where('status', 'error')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('message'),
            'stock_analysis' => 'disabled_in_stb_lite_mode',
        ];
    }

    private function resultStatus(array $results, int $failed): string
    {
        if ($failed > 0) {
            return 'warning';
        }

        foreach ($results as $result) {
            if (in_array(($result['status'] ?? ''), ['error', 'warning', 'partial_error'], true)) {
                return 'warning';
            }
        }

        return 'success';
    }

    private function finish(string $source, string $target, string $status, string $message, array $context): array
    {
        $this->runtime->logSync($source, $target, $status, $message);
        $this->runtime->markSchedulerTick($status, $message, $context);
        $this->runtime->logEvent($source, $message, $context);

        return [
            'status' => $status,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function compactResult(array $result): array
    {
        return collect($result)
            ->except(['items', 'orders', 'raw', 'response'])
            ->all();
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
