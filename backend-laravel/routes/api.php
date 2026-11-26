<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PrivacyController;
use App\Http\Controllers\Api\QrDiningController;
use App\Http\Controllers\Api\ReconciliationReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SecurityHardwareController;
use App\Http\Controllers\Api\SecurityOperationsController;
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
    Route::get('/auth/captcha', [AuthController::class, 'captchaChallenge'])->middleware('throttle:30,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/signup', [AuthController::class, 'signup'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

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

    // ==========================================
    // PUBLIC / DINING EXPLORATION (Guests & Diners)
    // ==========================================
    Route::get('/menu', [MenuController::class, 'index']);
    Route::get('/menu/categories', [MenuController::class, 'categories']);
    Route::get('/tables', [TableController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::get('/sessions/{session}/receipt', [ReconciliationReportController::class, 'sessionReceipt']);

    // Payment Gateway Webhooks (Cryptographically HMAC-verified)
    Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle']);

    // ==========================================
    // AUTHENTICATED STAFF & ADMIN API
    // ==========================================
    Route::middleware(['auth:sanctum', 'throttle:tenant-api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/revoke-all', [AuthController::class, 'revokeAllTokens']);
        Route::post('/auth/switch-tenant', [AuthController::class, 'switchTenant']);
        Route::post('/auth/mfa/setup', [AuthController::class, 'mfaSetup']);
        Route::post('/auth/mfa/enable', [AuthController::class, 'mfaEnable']);
        Route::post('/auth/mfa/disable', [AuthController::class, 'mfaDisable']);

        // Support Impersonation (Consented, Time-Limited, Audited - M24)
        Route::post('/auth/impersonation/grant-consent', [AuthController::class, 'grantImpersonationConsent']);
        Route::post('/auth/impersonation/start', [AuthController::class, 'startImpersonation']);
        Route::post('/auth/impersonation/stop', [AuthController::class, 'stopImpersonation']);

        // GDPR Data Privacy Export & Erasure (#84)
        Route::get('/privacy/export', [PrivacyController::class, 'export']);
        Route::delete('/privacy/erasure', [PrivacyController::class, 'erase']);

        // --- Administrative & Financial Management ---
        Route::middleware('role:admin,manager')->group(function () {
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::get('/payments/stats', [PaymentController::class, 'stats']);
            Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);
            Route::get('/reports/reconciliation', [ReconciliationReportController::class, 'dailyReconciliation']);

            Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('/audit-logs', function () {
                return response()->json(\App\Models\AuditLog::latest()->take(100)->get());
            });

            Route::get('/staff', [PrivacyController::class, 'listStaff']);
            Route::patch('/staff/{user}/status', [PrivacyController::class, 'updateStaffStatus']);
            Route::post('/staff/{user}/revoke-tokens', [PrivacyController::class, 'revokeStaffTokens']);

            // Security Operations, Monitoring, Bulk Export Alerts, Dual-Approval, & IoT (#111, #115, #123, #124, #131, #132, #133)
            Route::get('/security/alerts', [SecurityOperationsController::class, 'alerts']);
            Route::patch('/security/alerts/{alert}/resolve', [SecurityOperationsController::class, 'resolveAlert']);
            Route::get('/security/export-guests', [SecurityOperationsController::class, 'exportGuests']);
            Route::get('/security/readiness', [SecurityOperationsController::class, 'readiness']);
            Route::post('/security/approvals/payout-change', [SecurityHardwareController::class, 'requestPayoutChange']);
            Route::post('/security/approvals/{approval}/approve', [SecurityHardwareController::class, 'approveRequest']);
            Route::post('/iot/devices', [SecurityHardwareController::class, 'registerDevice']);

            Route::post('/tenants', [TenantController::class, 'store']);
            Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
            Route::post('/tenants/domain-challenge', [TenantController::class, 'requestDomainVerification']);
            Route::post('/tenants/verify-domain', [TenantController::class, 'verifyDomain']);
            Route::patch('/settings', [SettingsController::class, 'update']);

            Route::post('/tables', [TableController::class, 'store']);
            Route::post('/tables/{table}/regenerate-qr', [TableController::class, 'regenerateToken']);
            Route::post('/branches/toggle-qr', [TableController::class, 'toggleQrOrdering']);

            Route::post('/menu', [MenuController::class, 'store']);
            Route::post('/inventory', [InventoryController::class, 'store']);
            Route::patch('/inventory/{inventory}', [InventoryController::class, 'update']);
        });

        // --- Kitchen & Back of House (Kitchen, Staff, Manager, Admin) ---
        Route::middleware('role:kitchen,kitchen_staff,staff,waiter,admin,manager')->group(function () {
            Route::get('/dashboard/kitchen', [DashboardController::class, 'kitchenStats']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
            Route::patch('/orders/items/{item}/complete', [OrderController::class, 'completeItem']);
            Route::patch('/menu/{item}/availability', [MenuController::class, 'toggleAvailability']);
            Route::get('/inventory', [InventoryController::class, 'index']);
            Route::post('/inventory/deduct-recipe', [InventoryController::class, 'deductRecipe']);
        });

        // --- Floor Staff & Waiter Companion (Waiter, Staff, Manager, Admin) ---
        Route::middleware('role:waiter,staff,admin,manager')->group(function () {
            Route::prefix('waiter')->group(function () {
                Route::get('/tables', [WaiterController::class, 'tables']);
                Route::get('/alerts', [WaiterController::class, 'alerts']);
                Route::patch('/orders/{order}/serve', [WaiterController::class, 'serveOrder']);
                Route::post('/sessions/{session}/settle', [WaiterController::class, 'settleSession']);
                Route::patch('/tables/{table}/clean', [WaiterController::class, 'markClean']);
                Route::patch('/service-requests/{serviceRequest}/resolve', [WaiterController::class, 'resolveServiceRequest']);
            });

            Route::patch('/tables/{table}/status', [TableController::class, 'updateStatus']);

            Route::get('/reservations', [ReservationController::class, 'index']);
            Route::post('/reservations', [ReservationController::class, 'store']);
            Route::patch('/reservations/{reservation}/status', [ReservationController::class, 'updateStatus']);

            // Offline Resilience Sync Queue (#134)
            Route::post('/offline/sync', [SecurityOperationsController::class, 'syncOfflineQueue']);
        });

        // --- Digital Key & Door Lock Controls (#111, #112) ---
        Route::post('/iot/keys', [SecurityHardwareController::class, 'issueKey'])->middleware('role:waiter,staff,admin,manager');
        Route::post('/iot/unlock', [SecurityHardwareController::class, 'unlockDoor']);
    });
});
