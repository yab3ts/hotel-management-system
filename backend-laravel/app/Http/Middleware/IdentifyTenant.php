<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    /**
     * Handle an incoming request and resolve the current tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantIdentifier = $request->header('X-Tenant-ID')
            ?? $request->header('X-Tenant')
            ?? $request->query('tenant')
            ?? $request->route('tenant');

        $tenant = null;

        if ($tenantIdentifier) {
            if (is_numeric($tenantIdentifier)) {
                $tenant = Tenant::where('is_active', true)->find($tenantIdentifier);
            } else {
                $tenant = Tenant::where('is_active', true)->where('slug', $tenantIdentifier)->first();
            }
        }

        // If user is authenticated and tenant not explicitly provided in header,
        // use the user's current tenant
        if (!$tenant && $request->user()?->current_tenant_id) {
            $tenant = Tenant::where('is_active', true)->find($request->user()->current_tenant_id);
        }

        // Default fallback to the primary active tenant if available
        if (!$tenant) {
            $tenant = Tenant::where('is_active', true)->orderBy('id')->first();
        }

        if ($tenant) {
            TenantContext::setTenant($tenant);
            $request->attributes->set('tenant', $tenant);
        }

        $response = $next($request);

        // Append tenant header to response for transparency
        if ($tenant) {
            $response->headers->set('X-Tenant-ID', (string) $tenant->id);
            $response->headers->set('X-Tenant-Slug', $tenant->slug);
        }

        return $response;
    }
}
