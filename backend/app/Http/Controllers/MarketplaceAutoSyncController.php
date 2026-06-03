<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceSyncService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\StockConsistencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                $request->only(['type', 'status', 'date']),
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
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'source_marketplace' => ['required', 'in:shopee,tiktok'],
        ]);

        $result = $this->syncService->syncStockAnomaly($data['sku'], $data['source_marketplace']);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function exportOrderSync(Request $request)
    {
        $rows = $this->syncService->orderSyncExportRows($request->only(['type', 'status', 'date']));
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
        $result = $this->orderSyncService->retryOrderSyncLog($id);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function runSafetyCheck(): JsonResponse
    {
        return response()->json($this->stockConsistencyService->run());
    }

    public function syncShopeeToTiktok(): JsonResponse
    {
        set_time_limit(0);

        return response()->json($this->syncService->syncShopeeStocksToTiktok(true));
    }

    public function pollShopeeOrders(Request $request): JsonResponse
    {
        set_time_limit(0);

        return response()->json($this->orderSyncService->pollShopeeReadyOrders(
            (int) $request->integer('hours', 24)
        ));
    }

    public function pollTiktokOrders(Request $request): JsonResponse
    {
        set_time_limit(0);

        return response()->json($this->orderSyncService->pollTiktokUpdatedOrders(
            (int) $request->integer('hours', 24)
        ));
    }
}
