<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MarketplaceAutoSyncController;
use App\Http\Controllers\MarketplaceWebhookController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OmnichannelController;
use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['status' => 'ok', 'service' => 'agnishop-api']));
Route::get('shopee/callback', [OmnichannelController::class, 'shopeeCallback']);
Route::get('tiktok/callback', [OmnichannelController::class, 'tiktokCallback']);
Route::get('tiktok-callback', [OmnichannelController::class, 'tiktokCallback']);
Route::get('shopee-docs/modules', function () {
    $response = Http::withUserAgent('Mozilla/5.0')
        ->timeout(20)
        ->get('https://open.shopee.com/opservice/api/v1/doc/module/', ['version' => 2]);

    return response()->json($response->json(), $response->status());
});

Route::get('shopee-docs/api', function (Request $request) {
    $apiName = $request->query('api_name', 'v2.ams.get_open_campaign_added_product');

    $response = Http::withUserAgent('Mozilla/5.0')
        ->timeout(20)
        ->get('https://open.shopee.com/opservice/api/v1/doc/api/', [
            'api_name' => $apiName,
            'version' => 2,
        ]);

    return response()->json($response->json(), $response->status());
});

Route::post('omnichannel/{action}', [OmnichannelController::class, 'tokenAction'])
    ->where('action', 'connect-shopee(-agnishopbjm|-gitacollectionbjm)?|connect-tiktok(-agnishopbjm)?|auth-shopee(-agnishopbjm|-gitacollectionbjm)?|auth-tiktok(-agnishopbjm)?|get-token-shopee(-agnishopbjm|-gitacollectionbjm)?|get-token-tiktok(-agnishopbjm)?|refresh-token-shopee(-agnishopbjm|-gitacollectionbjm)?|refresh-token-tiktok(-agnishopbjm)?|get-auth-shop-tiktok(-agnishopbjm)?');

Route::get('omnichannel/dashboard', [OmnichannelController::class, 'dashboard']);
Route::get('get-shopee-items', [OmnichannelController::class, 'shopeeItems']);
Route::get('get-tiktok-items', [OmnichannelController::class, 'tiktokItems']);
Route::get('get-stock-master', [OmnichannelController::class, 'stockMaster']);
Route::get('product-variant-analysis', [OmnichannelController::class, 'productVariantAnalysis']);
Route::post('product-variant-analysis/confirm', [OmnichannelController::class, 'confirmProductVariantAnalysisIssue']);
Route::get('sku-mapping', [OmnichannelController::class, 'skuMapping']);
Route::post('sku-mapping', [OmnichannelController::class, 'saveSkuMapping']);
Route::post('sku-mapping/sync-marketplaces', [OmnichannelController::class, 'syncMarketplaceCaches']);
Route::post('sku-mapping/update-marketplace-sku', [OmnichannelController::class, 'updateSkuMappingMarketplaceSku']);
Route::post('sku-mapping/prepare-missing-variant', [OmnichannelController::class, 'prepareMissingVariant']);
Route::post('tiktok-variant/action', [OmnichannelController::class, 'tiktokVariantAction']);
Route::post('tiktok/submit-generated-payload', [OmnichannelController::class, 'tiktokSubmitGeneratedPayload']);
Route::post('tiktok/get-product', [OmnichannelController::class, 'tiktokGetProduct']);
Route::get('tiktok/get-product-context', [OmnichannelController::class, 'tiktokGetProductContext']);
Route::post('shopee/api-test', [OmnichannelController::class, 'shopeeApiTest']);
Route::get('shopee/api-test-context', [OmnichannelController::class, 'shopeeApiTestContext']);
Route::post('shopee/add-variant', [OmnichannelController::class, 'shopeeAddVariant']);
Route::match(['get', 'post'], 'sync-shopee-to-tiktok', [OmnichannelController::class, 'syncShopeeToTiktok']);
Route::get('marketplace/auto-sync', [MarketplaceAutoSyncController::class, 'dashboard']);
Route::get('marketplace/auto-sync/webhook-logs', [MarketplaceAutoSyncController::class, 'webhookLogs']);
Route::get('marketplace/auto-sync/sync-logs', [MarketplaceAutoSyncController::class, 'syncLogs']);
Route::get('marketplace/auto-sync/safety-check', [MarketplaceAutoSyncController::class, 'safety']);
Route::get('marketplace/auto-sync/order-sync', [MarketplaceAutoSyncController::class, 'orderSync']);
Route::get('marketplace/auto-sync/order-sync/export', [MarketplaceAutoSyncController::class, 'exportOrderSync']);
Route::get('marketplace/auto-sync/order-sync/{id}', [MarketplaceAutoSyncController::class, 'orderSyncDetail'])->whereNumber('id');
Route::post('marketplace/auto-sync/order-sync/{id}/retry', [MarketplaceAutoSyncController::class, 'retryOrderSync'])->whereNumber('id');
Route::post('marketplace/auto-sync/run-safety-check', [MarketplaceAutoSyncController::class, 'runSafetyCheck']);
Route::post('marketplace/auto-sync/sync-shopee-to-tiktok', [MarketplaceAutoSyncController::class, 'syncShopeeToTiktok']);
Route::post('marketplace/auto-sync/poll-shopee-orders', [MarketplaceAutoSyncController::class, 'pollShopeeOrders']);
Route::post('marketplace/auto-sync/poll-tiktok-orders', [MarketplaceAutoSyncController::class, 'pollTiktokOrders']);
Route::post('webhooks/shopee', [MarketplaceWebhookController::class, 'shopee']);
Route::post('webhooks/tiktok', [MarketplaceWebhookController::class, 'tiktok']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::apiResource('products', ProductController::class);
Route::apiResource('categories', CategoryController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

Route::get('cart', [CartController::class, 'show']);
Route::post('cart/items', [CartController::class, 'addItem']);
Route::put('cart/items/{item}', [CartController::class, 'updateItem']);
Route::delete('cart/items/{item}', [CartController::class, 'removeItem']);

Route::post('orders', [OrderController::class, 'checkout']);
Route::get('orders', [OrderController::class, 'index']);
Route::get('orders/{order}', [OrderController::class, 'show']);
