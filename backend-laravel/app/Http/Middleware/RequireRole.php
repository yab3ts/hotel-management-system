<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request and ensure authenticated user possesses at least one of the specified roles.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated. Valid Bearer token required.',
            ], 401);
        }

        if ($user->is_active === false) {
            $user->tokens()->delete();
            return response()->json([
                'error' => 'Account disabled. Please contact your administrator.',
            ], 403);
        }

        $tenant = TenantContext::getTenant();

        // Admins automatically inherit access to operational operations
        if ($user->hasRoleInTenant(['admin', 'manager'], $tenant?->id)) {
            return $next($request);
        }

        if (!$user->hasRoleInTenant($roles, $tenant?->id)) {
            return response()->json([
                'error' => 'Forbidden. Insufficient permissions for this action.',
                'required_roles' => $roles,
            ], 403);
        }

        return $next($request);
    }
}
