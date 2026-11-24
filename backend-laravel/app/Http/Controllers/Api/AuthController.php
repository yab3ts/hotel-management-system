<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * User registration (multi-tenant aware).
     */
    public function signup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $name = $validated['full_name'] ?? $validated['name'] ?? explode('@', $validated['email'])[0];

        // Security Hardening (CWE-269): Public signup strictly assigns 'customer' role.
        // Administrative and staff roles (admin, manager, kitchen) cannot be requested via public endpoints.
        $role = 'customer';
        $assignedRoles = ['customer'];

        $tenant = TenantContext::getTenant() ?? Tenant::first();

        $user = User::create([
            'name' => $name,
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'current_tenant_id' => $tenant?->id,
        ]);

        if ($tenant) {
            foreach ($assignedRoles as $r) {
                $tenant->users()->attach($user->id, ['role' => $r]);
            }
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'role' => $role,
                'roles' => $assignedRoles,
                'tenant_id' => $tenant?->id,
            ],
            'token' => $token,
            'session' => ['access_token' => $token],
            'roles' => $assignedRoles,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
        ], 201);
    }

    /**
     * User login.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'error' => 'Invalid email or password',
            ], 401);
        }

        $tenant = TenantContext::getTenant();
        if (!$tenant && $user->current_tenant_id) {
            $tenant = Tenant::find($user->current_tenant_id);
            TenantContext::setTenant($tenant);
        }
        if (!$tenant) {
            $tenant = Tenant::first();
            TenantContext::setTenant($tenant);
        }

        $roles = $user->rolesForTenant($tenant?->id);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'role' => $roles[0] ?? 'customer',
                'roles' => $roles,
                'tenant_id' => $tenant?->id,
            ],
            'token' => $token,
            'session' => ['access_token' => $token],
            'roles' => $roles,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'profile' => [
                'id' => $user->id,
                'full_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'preferences' => ['theme' => 'light', 'notifications' => true],
            ],
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = TenantContext::getTenant();
        $roles = $user->rolesForTenant($tenant?->id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'roles' => $roles,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'roles' => $roles,
        ]);
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Switch active tenant for user.
     */
    public function switchTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $user = $request->user();

        $user->update(['current_tenant_id' => $tenant->id]);
        TenantContext::setTenant($tenant);

        return response()->json([
            'message' => 'Active branch switched successfully',
            'tenant' => $tenant,
            'roles' => $user->rolesForTenant($tenant->id),
        ]);
    }
}
