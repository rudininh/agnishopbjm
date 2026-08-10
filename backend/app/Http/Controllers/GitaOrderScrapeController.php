<?php

namespace App\Http\Controllers;

use App\Services\GitaOrderScrapeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GitaOrderScrapeController extends Controller
{
    public function __construct(private readonly GitaOrderScrapeService $scrapeService)
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
