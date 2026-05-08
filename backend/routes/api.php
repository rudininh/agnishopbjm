<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
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
Route::get('sku-mapping', [OmnichannelController::class, 'skuMapping']);
Route::post('sku-mapping', [OmnichannelController::class, 'saveSkuMapping']);
Route::post('sku-mapping/prepare-missing-variant', [OmnichannelController::class, 'prepareMissingVariant']);
Route::post('tiktok-variant/action', [OmnichannelController::class, 'tiktokVariantAction']);
Route::post('tiktok/get-product', [OmnichannelController::class, 'tiktokGetProduct']);
Route::get('tiktok/get-product-context', [OmnichannelController::class, 'tiktokGetProductContext']);
Route::match(['get', 'post'], 'sync-shopee-to-tiktok', [OmnichannelController::class, 'syncShopeeToTiktok']);

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
