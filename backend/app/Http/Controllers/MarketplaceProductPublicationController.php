<?php

namespace App\Http\Controllers;

use App\Models\MarketplacePublicationRun;
use App\Models\Product;
use App\Services\MarketplaceProductPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MarketplaceProductPublicationController extends Controller
{
    public function __construct(private readonly MarketplaceProductPublisher $publisher)
    {
    }

    public function publish(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*.account_key' => ['required', 'string'],
            'accounts.*.context' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->publisher->publish($product->load('variants'), $this->accountContexts($data['accounts']));
        } catch (InvalidArgumentException $exception) {
            return $this->validationFailure($exception);
        }

        return response()->json(['data' => $this->runPayload($result['run'])], 201);
    }

    public function show(MarketplacePublicationRun $run): JsonResponse
    {
        return response()->json(['data' => $this->runPayload($run->load('results'))]);
    }

    public function retry(MarketplacePublicationRun $run): JsonResponse
    {
        $result = $this->publisher->retry($run->load(['product.variants', 'results']));

        return response()->json(['data' => $this->runPayload($result['run'])]);
    }

    private function accountContexts(array $accounts): array
    {
        $contexts = [];

        foreach ($accounts as $account) {
            $contexts[(string) $account['account_key']] = $account['context'] ?? [];
        }

        return $contexts;
    }

    private function validationFailure(InvalidArgumentException $exception): JsonResponse
    {
        $decoded = json_decode($exception->getMessage(), true);

        if (is_array($decoded)) {
            return response()->json([
                'message' => 'Produk belum siap dipublish ke marketplace.',
                'errors' => $decoded,
            ], 422);
        }

        return response()->json(['message' => $exception->getMessage()], 422);
    }

    private function runPayload(MarketplacePublicationRun $run): array
    {
        $run->loadMissing('results');

        return [
            'id' => $run->id,
            'product_uuid' => $run->product_uuid,
            'status' => $run->status,
            'requested_accounts' => $run->requested_accounts,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'results' => $run->results->map(fn ($result): array => [
                'account_key' => $result->account_key,
                'channel' => $result->channel,
                'status' => $result->status,
                'remote_product_id' => $result->remote_product_id,
                'remote_variant_ids' => $result->remote_variant_ids,
                'error_code' => $result->error_code,
                'error_message' => $result->error_message,
                'updated_at' => $result->updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}