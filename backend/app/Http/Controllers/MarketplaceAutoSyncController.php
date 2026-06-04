<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceSyncService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\StockConsistencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceAutoSyncController extends Controller
{
    public function __construct(
        private readonly MarketplaceSyncService $syncService,
        private readonly MarketplaceOrderSyncService $orderSyncService,
        private readonly StockConsistencyService $stockConsistencyService,
    ) {
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'data' => [
                ...$this->syncService->dashboard(),
                'safety' => $this->syncService->safetySummary(),
            ],
        ]);
    }

    public function webhookLogs(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->webhookLogs(
                $request->only(['marketplace', 'status', 'date']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function syncLogs(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->syncLogs(
                $request->only(['marketplace', 'status', 'date']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function safety(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'summary' => $this->syncService->safetySummary(),
            ...$this->syncService->safetyHistory(
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function orderSync(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->orderSyncHistory(
                $request->only(['type', 'status', 'date', 'search']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function stockAnomalies(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->stockAnomalies(
                $request->only(['type', 'search']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 30)
            ),
        ]);
    }

    public function syncStockAnomaly(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'source_marketplace' => ['required', 'in:shopee,tiktok'],
        ]);

        $result = $this->syncService->syncStockAnomaly($data['sku'], $data['source_marketplace']);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function exportOrderSync(Request $request)
    {
        $rows = $this->syncService->orderSyncExportRows($request->only(['type', 'status', 'date', 'search']));
        $filename = 'order-sync-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['time', 'type', 'target', 'order_or_sku', 'old_stock', 'new_stock', 'status', 'message']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->created_at,
                    $row->source_marketplace,
                    $row->target_marketplace,
                    $row->sku,
                    $row->old_stock,
                    $row->new_stock,
                    $row->status,
                    $row->message,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function orderSyncDetail(int $id): JsonResponse
    {
        $detail = $this->syncService->orderSyncDetail($id);

        return response()->json($detail, ($detail['status'] ?? 'ok') === 'error' ? 404 : 200);
    }

    public function retryOrderSync(int $id): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $result = $this->orderSyncService->retryOrderSyncLog($id);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function runSafetyCheck(): JsonResponse
    {
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->stockConsistencyService->run());
    }

    public function syncShopeeToTiktok(): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->syncService->syncShopeeStocksToTiktok(true));
    }

    public function instantCheck(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $marketplace = strtolower((string) $request->input('marketplace', 'all'));
        abort_if(! in_array($marketplace, ['all', 'shopee', 'tiktok'], true), 422, 'Marketplace instant check tidak valid.');

        $result = [
            'status' => 'success',
            'message' => 'Instant check order 1 jam terakhir selesai.',
            'shopee' => null,
            'tiktok' => null,
        ];

        if (in_array($marketplace, ['all', 'shopee'], true)) {
            $result['shopee'] = $this->orderSyncService->pollShopeeReadyOrders(1);
        }
        if (in_array($marketplace, ['all', 'tiktok'], true)) {
            $result['tiktok'] = $this->orderSyncService->pollTiktokUpdatedOrders(1);
        }

        $failed = (int) data_get($result, 'shopee.failed', 0) + (int) data_get($result, 'tiktok.failed', 0);
        if ($failed > 0) {
            $result['status'] = 'warning';
            $result['message'] = 'Instant check selesai dengan sebagian gagal.';
        }

        return response()->json($result);
    }

    public function retryOpenIssues(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $limit = max(1, min(50, (int) $request->integer('limit', 10)));
        $logs = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'sku', 'source_marketplace', 'status']);

        $results = [];
        $success = 0;
        $failed = 0;
        foreach ($logs as $log) {
            try {
                $result = $this->orderSyncService->retryOrderSyncLog((int) $log->id);
            } catch (\Throwable $exception) {
                $result = ['status' => 'error', 'message' => $exception->getMessage()];
            }

            $isOk = ($result['status'] ?? '') !== 'error';
            $success += $isOk ? 1 : 0;
            $failed += $isOk ? 0 : 1;
            $results[] = [
                'id' => (int) $log->id,
                'sku' => $log->sku,
                'type' => $log->source_marketplace,
                'old_status' => $log->status,
                'status' => $result['status'] ?? 'unknown',
                'message' => $result['message'] ?? null,
            ];
        }

        return response()->json([
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => $logs->isEmpty()
                ? 'Tidak ada issue terbuka untuk di-retry.'
                : 'Retry issue terbuka selesai diproses.',
            'checked' => $logs->count(),
            'success' => $success,
            'failed' => $failed,
            'items' => $results,
        ]);
    }

    public function skuChangeHistory(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->skuChangeHistory(
                $request->only(['channel', 'status', 'date', 'search']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function orderWatchdog(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->orderWatchdog(
                (int) $request->integer('minutes', 5),
                (int) $request->integer('hours', 24)
            ),
        ]);
    }

    public function reconciliationReport(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->reconciliationReport($request->only(['type', 'search'])),
        ]);
    }

    public function queueDashboard(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->syncQueueDashboard(
                (int) $request->integer('hours', 24),
                (int) $request->integer('limit', 50)
            ),
        ]);
    }

    public function bulkUpdateEmptySkus(Request $request): JsonResponse
    {
        set_time_limit(0);
        if (! $request->boolean('dry_run', false)) {
            app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        }
        $limit = max(1, min(50, (int) $request->integer('limit', 20)));
        $dryRun = $request->boolean('dry_run', false);

        $skuMappingRequest = Request::create('/api/sku-mapping', 'GET', [
            'status' => 'all',
            'per_page' => 5000,
            'page' => 1,
            'compact' => 1,
        ]);
        $mappingPayload = app(OmnichannelController::class)->skuMapping($skuMappingRequest)->getData(true);
        $items = collect($mappingPayload['items'] ?? []);

        $candidates = $items->filter(function (array $item): bool {
            $templateSku = trim((string) ($item['template_sku'] ?? $item['seller_sku'] ?? $item['internal_sku'] ?? ''));
            $stockMasterId = (int) ($item['stock_master_id'] ?? 0);
            $shopee = $item['shopee'] ?? [];
            $tiktok = $item['tiktok'] ?? [];
            $hasMissingShopee = trim((string) ($shopee['item_id'] ?? '')) !== ''
                && trim((string) ($shopee['model_id'] ?? '')) !== ''
                && trim((string) ($shopee['seller_sku'] ?? '')) === '';
            $hasMissingTiktok = trim((string) ($tiktok['product_id'] ?? '')) !== ''
                && trim((string) ($tiktok['sku_id'] ?? '')) !== ''
                && trim((string) ($tiktok['seller_sku'] ?? '')) === '';

            return $stockMasterId > 0 && $templateSku !== '' && ($hasMissingShopee || $hasMissingTiktok);
        })->values();

        $results = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        foreach ($candidates->take($limit) as $item) {
            $shopee = $item['shopee'] ?? [];
            $tiktok = $item['tiktok'] ?? [];
            $applyShopee = trim((string) ($shopee['item_id'] ?? '')) !== ''
                && trim((string) ($shopee['model_id'] ?? '')) !== ''
                && trim((string) ($shopee['seller_sku'] ?? '')) === '';
            $applyTiktok = trim((string) ($tiktok['product_id'] ?? '')) !== ''
                && trim((string) ($tiktok['sku_id'] ?? '')) !== ''
                && trim((string) ($tiktok['seller_sku'] ?? '')) === '';
            $templateSku = trim((string) ($item['template_sku'] ?? $item['seller_sku'] ?? $item['internal_sku'] ?? ''));

            $payload = [
                'stock_master_id' => (int) $item['stock_master_id'],
                'seller_sku' => $templateSku,
                'apply_shopee' => $applyShopee ? 'true' : 'false',
                'apply_tiktok' => $applyTiktok ? 'true' : 'false',
                'dry_run' => $dryRun ? 'true' : 'false',
            ];

            try {
                $response = app(OmnichannelController::class)
                    ->updateSkuMappingMarketplaceSku(Request::create('/api/sku-mapping/update-marketplace-sku', 'POST', $payload))
                    ->getData(true);
                $rowStatus = in_array(($response['status'] ?? ''), ['ok', 'success'], true) ? 'success' : ($response['status'] ?? 'warning');
            } catch (\Throwable $exception) {
                $response = ['status' => 'error', 'message' => $exception->getMessage()];
                $rowStatus = 'error';
            }

            $processed += 1;
            $isFailed = in_array($rowStatus, ['error', 'partial_error'], true);
            $success += $isFailed ? 0 : 1;
            $failed += $isFailed ? 1 : 0;
            $results[] = [
                'stock_master_id' => (int) $item['stock_master_id'],
                'product_name' => $item['product_name'] ?? null,
                'variant_name' => $item['variant_name'] ?? null,
                'seller_sku' => $templateSku,
                'apply_shopee' => $applyShopee,
                'apply_tiktok' => $applyTiktok,
                'status' => $rowStatus,
                'message' => $response['message'] ?? null,
            ];
        }

        return response()->json([
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => $dryRun ? 'Preview bulk update SKU kosong selesai.' : 'Bulk update SKU kosong selesai diproses.',
            'total_candidates' => $candidates->count(),
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'items' => $results,
        ]);
    }

    public function pollShopeeOrders(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->orderSyncService->pollShopeeReadyOrders(
            (int) $request->integer('hours', 24)
        ));
    }

    public function pollTiktokOrders(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->orderSyncService->pollTiktokUpdatedOrders(
            (int) $request->integer('hours', 24)
        ));
    }
}
