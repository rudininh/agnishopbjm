<?php

namespace App\Http\Controllers;

use App\Services\GitaOrderScrapeService;
use App\Services\GitaOrderStockSyncService;
use App\Services\GitaOrderScrapeWorkerLeaseService;
use App\Services\GitaOrderScrapeWorkerLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GitaOrderScrapeController extends Controller
{
    public function __construct(
        private readonly GitaOrderScrapeService $scrapeService,
        private readonly GitaOrderStockSyncService $stockSyncService,
        private readonly GitaOrderScrapeWorkerLeaseService $workerLeaseService,
        private readonly GitaOrderScrapeWorkerLauncher $workerLauncher,
    )
    {
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->authorizedWorker($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            return response()->json([
                'data' => $this->scrapeService->record($request->all()),
            ], 201);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    public function latest(): JsonResponse
    {
        return response()->json([
            'data' => $this->scrapeService->latestRun(),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'match_status' => ['nullable', 'string', Rule::in(['matched', 'unmatched', 'duplicate_master_sku'])],
                'tab_status' => ['nullable', 'string', Rule::in(['to_ship', 'shipped'])],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        return response()->json($this->scrapeService->items([
            'match_status' => $data['match_status'] ?? null,
            'tab_status' => $data['tab_status'] ?? null,
        ], (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50)));
    }

    public function syncItem(int $item): JsonResponse
    {
        return response()->json(['data' => $this->stockSyncService->syncItem($item)]);
    }

    public function syncLatest(): JsonResponse
    {
        return response()->json(['data' => $this->stockSyncService->syncLatest()]);
    }

    public function claimWorkerLease(Request $request): JsonResponse
    {
        if (! $this->authorizedWorker($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $this->workerLeaseService->claim();

        return response()->json(['data' => $data], match ($data['status']) {
            'already_running' => 409,
            'marketplace_busy' => 423,
            default => 200,
        });
    }

    public function renewWorkerLease(Request $request): JsonResponse
    {
        if (! $this->authorizedWorker($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['lease_token' => ['required', 'string']]);
        abort_unless($this->workerLeaseService->renew($data['lease_token']), 409, 'Lease scraper Gita tidak aktif.');

        return response()->json(['data' => ['status' => 'renewed']]);
    }

    public function releaseWorkerLease(Request $request): JsonResponse
    {
        if (! $this->authorizedWorker($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['lease_token' => ['required', 'string']]);
        abort_unless($this->workerLeaseService->release($data['lease_token']), 409, 'Lease scraper Gita tidak aktif.');

        return response()->json(['data' => ['status' => 'released']]);
    }

    public function wakeWorker(): JsonResponse
    {
        $data = $this->workerLauncher->wake();

        return response()->json(['data' => $data], match ($data['status']) {
            'already_running' => 409,
            'marketplace_busy' => 423,
            'manual_required' => 503,
            default => 200,
        });
    }

    private function authorizedWorker(Request $request): bool
    {
        $configuredToken = trim((string) config('gita_order_scraper.ingest_token', ''));
        $providedToken = trim((string) $request->bearerToken());

        return $configuredToken !== ''
            && $providedToken !== ''
            && hash_equals($configuredToken, $providedToken);
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422);
    }
}
