<?php

namespace App\Http\Controllers;

use App\Services\ShopeeWebhookService;
use App\Services\TikTokWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceWebhookController extends Controller
{
    public function shopee(Request $request, ShopeeWebhookService $service): JsonResponse
    {
        $result = $service->handle($request);

        return response()->json($result, ($result['status'] ?? '') === 'error' ? 422 : 200);
    }

    public function tiktok(Request $request, TikTokWebhookService $service): JsonResponse
    {
        $result = $service->handle($request);

        return response()->json($result, ($result['status'] ?? '') === 'error' ? 422 : 200);
    }
}
