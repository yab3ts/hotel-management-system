<?php

namespace App\Http\Middleware;

use App\Models\SecurityAlert;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    private const RESERVED_SUBDOMAINS = [
        'www', 'api', 'app', 'admin', 'administrator', 'mail', 'status', 'root',
        'auth', 'billing', 'dashboard', 'support', 'internal', 'metrics', 'health',
        'system', 'superadmin', 'cdn', 'vpn', 'stage', 'staging', 'dev', 'test'
    ];

    /**
     * Handle an incoming request and resolve the current tenant (supports wildcard subdomains).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;
        $host = strtolower(explode(':', (string) $request->getHost())[0]);

        // 1. Wildcard subdomain tenant resolution (e.g. roma.tableza.app or cafex.localhost)
        foreach ((array) config('app.wildcard_domains', []) as $baseDomain) {
            $baseDomain = strtolower($baseDomain);
            if ($host !== $baseDomain && str_ends_with($host, '.' . $baseDomain)) {
                $subdomain = substr($host, 0, -(strlen($baseDomain) + 1));
                if (!empty($subdomain) && !str_contains($subdomain, '.') && !in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
                    $tenant = Tenant::where('is_active', true)->where('slug', $subdomain)->first();
                    if (!$tenant) {
                        return response()->json([
                            'error' => 'Tenant subdomain not found or inactive.',
                        ], 404);
                    }
                }
                break;
            }
        }

        // 2. Verified custom domain lookup (M21)
        if (!$tenant && !empty($host)) {
            $tenant = Tenant::where('is_active', true)
                ->where('domain', $host)
                ->whereNotNull('domain_verified_at')
                ->first();
        }

        // 3. Explicit header / query / route parameter
        if (!$tenant) {
            $tenantIdentifier = $request->header('X-Tenant-ID')
                ?? $request->header('X-Tenant')
                ?? $request->query('tenant')
                ?? $request->route('tenant');

            if ($tenantIdentifier) {
                if (is_numeric($tenantIdentifier)) {
                    $tenant = Tenant::where('is_active', true)->find($tenantIdentifier);
                } else {
                    $tenant = Tenant::where('is_active', true)->where('slug', $tenantIdentifier)->first();
                }
            }
        }

        $user = $request->user('sanctum') ?? $request->user();

        // 4. Authenticated user's active tenant
        if (!$tenant && $user?->current_tenant_id) {
            $tenant = Tenant::where('is_active', true)->find($user->current_tenant_id);
        }

        // 5. Primary active tenant fallback
        if (!$tenant) {
            $tenant = Tenant::where('is_active', true)->orderBy('id')->first();
        }

        // Security (Cross-Tenant BOLA/IDOR & M6 prevention):
        // Authenticated users must belong to the resolved tenant (tenant admins cannot cross into other tenants).
        if ($user && $tenant && !$request->is('api/t/*') && !$request->is('api/qr/*') && !$request->is('api/auth/login') && !$request->is('api/auth/signup') && !$request->is('api/auth/logout') && !$request->is('api/auth/impersonation/*')) {
            $hasAccess = $user->tenants()->where('tenants.id', $tenant->id)->exists();

            if (!$hasAccess) {
                SecurityAlert::raise(
                    'cross_tenant_attempt',
                    "User #{$user->id} ({$user->email}) attempted cross-tenant access to tenant #{$tenant->id} ({$tenant->slug}).",
                    'high',
                    $user->id,
                    ['target_tenant_id' => $tenant->id, 'path' => $request->path()],
                    $user->current_tenant_id
                );

                return response()->json([
                    'error' => 'Forbidden: You do not have authorization to access this tenant organization.',
                ], 403);
            }
        }

        if ($tenant) {
            TenantContext::setTenant($tenant);
            $request->attributes->set('tenant', $tenant);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.bypass_rls', 'off', false)");
                DB::statement("SELECT set_config('app.current_tenant_id', ?, false)", [(string) $tenant->id]);
            }
        } else {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.bypass_rls', 'off', false)");
                DB::statement("SELECT set_config('app.current_tenant_id', '', false)");
            }
        }

        try {
            $response = $next($request);

            // Append tenant header to response for transparency
            if ($tenant) {
                $response->headers->set('X-Tenant-ID', (string) $tenant->id);
                $response->headers->set('X-Tenant-Slug', $tenant->slug);
            }

            // Flag impersonated responses explicitly (M24)
            $token = $user?->currentAccessToken();
            if ($token && method_exists($token, 'can') && $token->can('impersonation')) {
                $response->headers->set('X-Impersonation-Active', 'true');
            }

            return $response;
        } finally {
            TenantContext::clear();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.current_tenant_id', '', false)");
                DB::statement("SELECT set_config('app.bypass_rls', 'off', false)");
            }
        }
    }
}
