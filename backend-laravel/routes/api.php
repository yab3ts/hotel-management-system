<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\QrDiningController;
use App\Http\Controllers\Api\ReconciliationReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\WaiterController;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Café X Laravel Multi-Tenant & QR Ordering API',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Demo reset endpoint (strictly guarded: forbidden in production & requires DEMO_MODE=true)
Route::post('/demo/reset', function () {
    if (app()->isProduction() || !config('app.demo_mode')) {
        return response()->json([
            'error' => 'Demo reset is disabled. It requires DEMO_MODE=true in environment and is forbidden in production.'
        ], 403);
    }
    if (!app()->runningUnitTests()) {
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }
    \App\Models\AuditLog::record('demo_data_reset', null, [
        'reason' => 'One-click showcase demo reset executed',
        'ip' => request()->ip(),
        'user_agent' => request()->userAgent(),
    ]);
    return response()->json([
        'message' => 'Demo data reset and seeded successfully.',
        'timestamp' => now()->toIso8601String(),
    ]);
})->middleware('throttle:3,5');

// All API routes go through IdentifyTenant to resolve the active hotel / restaurant branch
Route::middleware([IdentifyTenant::class])->group(function () {
    // Authentication (Public)
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/signup', [AuthController::class, 'signup'])->middleware('throttle:10,1');

    // Multi-tenant & Branches
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);

    // ==========================================
    // QR TABLE ORDERING FLOW (Public & Guests)
    // Canonical: /t/{token} (with /qr/{token} alias)
    // ==========================================
    $qrRoutes = function () {
        Route::get('/', [QrDiningController::class, 'scan'])->middleware('throttle:60,1');
        Route::post('/join', [QrDiningController::class, 'join'])->middleware('throttle:qr-join');
        Route::post('/order', [QrDiningController::class, 'placeOrder'])->middleware('throttle:qr-order');
        Route::post('/orders/{order}/cancel', [QrDiningController::class, 'cancelOrder'])->middleware('throttle:qr-order');
        Route::get('/tab', [QrDiningController::class, 'getTableTab'])->middleware('throttle:60,1');
        Route::post('/pay', [QrDiningController::class, 'pay'])->middleware('throttle:qr-pay');
        Route::post('/call-waiter', [QrDiningController::class, 'callWaiter'])->middleware('throttle:qr-service');
        Route::post('/request-bill', [QrDiningController::class, 'requestBill'])->middleware('throttle:qr-service');
    };

    Route::prefix('t/{token}')->group($qrRoutes);
    Route::prefix('qr/{token}')->group($qrRoutes);

    // Public / Dining exploration
    Route::get('/menu', [MenuController::class, 'index']);
    Route::get('/menu/categories', [MenuController::class, 'categories']);
    Route::post('/menu', [MenuController::class, 'store']);
    Route::get('/tables', [TableController::class, 'index']);
    Route::patch('/tables/{table}/status', [TableController::class, 'updateStatus']);
    Route::post('/tables/{table}/regenerate-qr', [TableController::class, 'regenerateToken']);
    Route::post('/branches/toggle-qr', [TableController::class, 'toggleQrOrdering']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::patch('/orders/items/{item}/complete', [OrderController::class, 'completeItem']);
    Route::patch('/menu/{item}/availability', [MenuController::class, 'toggleAvailability']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/stats', [PaymentController::class, 'stats']);
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/audit-logs', function () {
        return response()->json(\App\Models\AuditLog::latest()->take(100)->get());
    });

    // Payment Gateway Webhooks
    Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle']);

    // Financial Reconciliation & Tax Receipts
    Route::get('/reports/reconciliation', [ReconciliationReportController::class, 'dailyReconciliation'])->middleware('auth:sanctum');
    Route::get('/sessions/{session}/receipt', [ReconciliationReportController::class, 'sessionReceipt']);

    // Inventory Management
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory', [InventoryController::class, 'store']);
    Route::patch('/inventory/{inventory}', [InventoryController::class, 'update']);
    Route::post('/inventory/deduct-recipe', [InventoryController::class, 'deductRecipe']);

    // Venue & System Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update'])->middleware('auth:sanctum');

    // ==========================================
    // WAITER & FLOOR STAFF API
    // ==========================================
    Route::prefix('waiter')->group(function () {
        Route::get('/tables', [WaiterController::class, 'tables']);
        Route::get('/alerts', [WaiterController::class, 'alerts']);
        Route::patch('/orders/{order}/serve', [WaiterController::class, 'serveOrder']);
        Route::post('/sessions/{session}/settle', [WaiterController::class, 'settleSession']);
        Route::patch('/tables/{table}/clean', [WaiterController::class, 'markClean']);
        Route::patch('/service-requests/{serviceRequest}/resolve', [WaiterController::class, 'resolveServiceRequest']);
    });

    // Authenticated API routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/switch-tenant', [AuthController::class, 'switchTenant']);

        // Manage Tenants
        Route::post('/tenants', [TenantController::class, 'store']);

        // Menu Management
        Route::post('/menu', [MenuController::class, 'store']);
        Route::patch('/menu/{item}/availability', [MenuController::class, 'toggleAvailability']);

        // Tables Management
        Route::post('/tables', [TableController::class, 'store']);
        Route::patch('/tables/{table}/status', [TableController::class, 'updateStatus']);

        // Reservations
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::patch('/reservations/{reservation}/status', [ReservationController::class, 'updateStatus']);

        // Dashboards & Analytics
        Route::get('/dashboard/kitchen', [DashboardController::class, 'kitchenStats']);
    });
});
