<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OmnichannelController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['status' => 'ok', 'service' => 'agnishop-api']));
Route::post('omnichannel/{action}', [OmnichannelController::class, 'tokenAction'])
    ->where('action', 'auth-shopee|auth-tiktok|get-token-shopee|get-token-tiktok|refresh-token-shopee|refresh-token-tiktok|get-auth-shop-tiktok');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('omnichannel/dashboard', [OmnichannelController::class, 'dashboard']);
    Route::get('get-shopee-items', [OmnichannelController::class, 'shopeeItems']);
    Route::get('get-tiktok-items', [OmnichannelController::class, 'tiktokItems']);
    Route::get('get-stock-master', [OmnichannelController::class, 'stockMaster']);
    Route::match(['get', 'post'], 'sync-shopee-to-tiktok', [OmnichannelController::class, 'syncShopeeToTiktok']);
});

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'addItem']);
    Route::put('cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem']);

    Route::post('orders', [OrderController::class, 'checkout']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
});
