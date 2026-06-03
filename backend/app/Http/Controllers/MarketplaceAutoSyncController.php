<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceSyncService;
use App\Services\StockConsistencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceAutoSyncController extends Controller
{
    public function __construct(
        private readonly MarketplaceSyncService $syncService,
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

    public function runSafetyCheck(): JsonResponse
    {
        return response()->json($this->stockConsistencyService->run());
    }
}
