<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\LoadBalancerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Middleware\ConcurrencyLimiter;
use App\Http\Middleware\LoadDistributor;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — E-Commerce High-Performance Backend
|--------------------------------------------------------------------------
|
| Rate Limiting (NFR #2):
|   - 'api' throttle: 60 requests/minute per user (Laravel built-in)
|   - ConcurrencyLimiter middleware: max concurrent requests per endpoint
|
*/

// Public routes — no authentication required
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
});

// Products — readable publicly
Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

// Protected routes — Sanctum authentication required
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });

    // Product management (admin operations)
    Route::prefix('products')->name('products.')->group(function () {
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::put('{product}', [ProductController::class, 'update'])->name('update');
        Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');
    });

    // Cart
    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('items', [CartController::class, 'add'])->name('add');
        Route::put('items/{cartItemId}', [CartController::class, 'update'])->name('update');
        Route::delete('items/{cartItemId}', [CartController::class, 'remove'])->name('remove');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');
    });

    // Orders — NFR #1 (race condition prevention) + NFR #2 (concurrency limit) + NFR #3 (async)
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{order}', [OrderController::class, 'show'])->name('show');

        /*
         * NFR #2: ConcurrencyLimiter(5) — only 5 simultaneous checkout requests allowed.
         * This prevents DB connection exhaustion and cascading failures under spike traffic.
         */
        Route::post('/', [OrderController::class, 'store'])
            ->middleware(ConcurrencyLimiter::class . ':5')
            ->name('store');

        Route::patch('{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');
    });

    // Inventory management
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
        Route::post('{product}/restock', [InventoryController::class, 'restock'])->name('restock');
        Route::put('{product}/adjust', [InventoryController::class, 'adjust'])->name('adjust');
    });

    // NFR #4 — Batch Processing: تقارير المبيعات اليومية
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('daily', [ReportController::class, 'listReports'])->name('daily.index');
        Route::post('daily', [ReportController::class, 'requestDailySalesReport'])->name('daily.request');
        Route::get('daily/{date}', [ReportController::class, 'showDailySalesReport'])->name('daily.show');
    });

    // NFR #5 — Load Distribution: مراقبة وتوزيع الأحمال
    // LoadDistributor middleware يوزّع الطلبات الثقيلة على nodes مختلفة
    Route::prefix('load-balancer')->name('lb.')->group(function () {
        Route::get('stats', [LoadBalancerController::class, 'stats'])->name('stats');
        Route::get('nodes', [LoadBalancerController::class, 'nodes'])->name('nodes');
        Route::post('simulate', [LoadBalancerController::class, 'simulate'])->name('simulate');
        Route::delete('reset', [LoadBalancerController::class, 'reset'])->name('reset');
    });
});
