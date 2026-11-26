<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Multi-tier rate limiters: per-guest inner limit + per-table and per-IP outer caps
        // to protect shared café Wi-Fi NAT IPs while preventing secret-minting flood attacks
        RateLimiter::for('qr-order', function (Request $request) {
            $secretKey = $request->header('X-Guest-Secret') 
                ?? $request->input('guest_secret') 
                ?? $request->route('token') 
                ?? $request->ip();
            $tokenKey = $request->route('token') ?? 'table-unknown';

            return [
                Limit::perMinute(25)->by('guest:'.$secretKey),
                Limit::perMinute(60)->by('table:'.$tokenKey),
                Limit::perMinute(120)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('qr-join', function (Request $request) {
            $secretKey = $request->header('X-Guest-Secret') ?? $request->ip();
            $tokenKey = $request->route('token') ?? 'table-unknown';

            return [
                Limit::perMinute(15)->by('guest:'.$secretKey),
                Limit::perMinute(30)->by('table:'.$tokenKey),
                Limit::perMinute(60)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('qr-pay', function (Request $request) {
            $secretKey = $request->header('X-Guest-Secret') ?? $request->ip();
            $tokenKey = $request->route('token') ?? 'table-unknown';

            return [
                Limit::perMinute(15)->by('guest:'.$secretKey),
                Limit::perMinute(30)->by('table:'.$tokenKey),
                Limit::perMinute(60)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('qr-service', function (Request $request) {
            $tokenKey = $request->route('token') ?? 'table-unknown';

            return [
                Limit::perMinute(10)->by('guest:'.($request->header('X-Guest-Secret') ?? $request->ip())),
                Limit::perMinute(20)->by('table:'.$tokenKey),
                Limit::perMinute(40)->by('ip:'.$request->ip()),
            ];
        });

        // Per-tenant quota limiter (M12 Noisy neighbor defense)
        RateLimiter::for('tenant-api', function (Request $request) {
            $tenantId = \App\Tenancy\TenantContext::getTenantId() ?? $request->header('X-Tenant-ID') ?? 'default';
            return Limit::perMinute(300)->by('tenant:' . $tenantId);
        });

        // Set RLS bypass for console commands (migrations, seeders, backup drills)
        if (app()->runningInConsole() && \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            try {
                \Illuminate\Support\Facades\DB::statement("SELECT set_config('app.bypass_rls', 'on', false)");
            } catch (\Throwable $e) {
                // Connection may not be ready during initial boot
            }
        }
    }
}
