<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    /**
     * List all active hotel / restaurant branches.
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::where('is_active', true)
            ->withCount(['tables', 'menuItems'])
            ->get();

        return response()->json($tenants);
    }

    /**
     * Show specific branch details.
     */
    public function show(string $idOrSlug): JsonResponse
    {
        $tenant = is_numeric($idOrSlug)
            ? Tenant::withCount(['tables', 'menuItems'])->findOrFail($idOrSlug)
            : Tenant::withCount(['tables', 'menuItems'])->where('slug', $idOrSlug)->firstOrFail();

        return response()->json($tenant);
    }

    /**
     * Register a new Hotel or Restaurant branch (Multi-tenant onboarding).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:tenants,slug'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['slug'] = $slug;

        $tenant = Tenant::create($validated);

        // If authenticated user created this branch, assign them as admin
        if ($user = $request->user()) {
            $tenant->users()->attach($user->id, ['role' => 'admin']);
            $user->update(['current_tenant_id' => $tenant->id]);
        }

        return response()->json([
            'message' => 'New branch created successfully',
            'tenant' => $tenant,
        ], 201);
    }
}
