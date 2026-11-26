<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public const RESERVED_SLUGS = [
        'admin', 'administrator', 'api', 'app', 'auth', 'billing', 'cdn', 'dashboard',
        'dev', 'dns', 'docs', 'health', 'internal', 'login', 'mail', 'metrics',
        'pay', 'payment', 'portal', 'register', 'root', 'security', 'signin', 'signup',
        'ssl', 'stage', 'staging', 'static', 'status', 'superadmin', 'support', 'system',
        'test', 'vpn', 'webhook', 'webhooks', 'www',
    ];

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
     * Register a new Hotel or Restaurant branch (Multi-tenant onboarding, M14 hardened).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tenants,name'],
            'slug' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string'],
        ]);

        $rawSlug = (string) ($validated['slug'] ?? Str::slug($validated['name']));
        // Unicode NFKC normalization to prevent homoglyph/lookalike spoofing
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($rawSlug, \Normalizer::FORM_KC) ?: $rawSlug;
        } else {
            $normalized = $rawSlug;
        }
        $slug = strtolower(trim($normalized));

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])?$/', $slug)) {
            return response()->json([
                'error' => 'Tenant slug must consist only of lowercase letters, numbers, and internal hyphens (3-30 chars).',
            ], 422);
        }

        if (in_array($slug, self::RESERVED_SLUGS, true) || Tenant::where('slug', $slug)->exists()) {
            return response()->json([
                'error' => 'Tenant slug is reserved or already in use.',
            ], 422);
        }
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

    /**
     * Decommission / delete a tenant branch.
     * Cleans up custom domain records, revokes active IoT digital keys, and disables orphaned tokens.
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        // 1. Revoke active digital keys
        \App\Models\DigitalKey::where('tenant_id', $tenant->id)->update([
            'is_revoked' => true,
            'revoked_at' => now(),
            'revocation_reason' => 'tenant_decommissioned',
        ]);

        // 2. Clear verified custom domain DNS records & routing
        $tenant->update([
            'domain' => null,
            'domain_verification_token' => null,
            'domain_verified_at' => null,
            'is_active' => false,
        ]);

        $tenant->delete();

        \App\Models\AuditLog::record('tenant_decommissioned', $tenant, ['tenant_id' => $tenant->id]);

        return response()->json([
            'message' => 'Tenant branch decommissioned, DNS routing dismantled, and active digital keys revoked.',
        ]);
    }

    /**
     * Initiate custom domain ownership verification via DNS TXT challenge (M21).
     */
    public function requestDomainVerification(Request $request): JsonResponse
    {
        $tenant = \App\Tenancy\TenantContext::getTenant();
        if (!$tenant) {
            return response()->json(['error' => 'Active tenant required.'], 400);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'unique:tenants,domain,' . $tenant->id],
        ]);

        $domain = strtolower(trim($validated['domain']));
        $token = 'tableza-verify=' . bin2hex(random_bytes(16));

        $tenant->update([
            'domain' => $domain,
            'domain_verification_token' => $token,
            'domain_verified_at' => null,
        ]);

        return response()->json([
            'domain' => $domain,
            'txt_record_name' => "_tableza-challenge.{$domain}",
            'txt_record_value' => $token,
            'verified' => false,
        ]);
    }

    /**
     * Confirm custom domain DNS TXT verification (M21).
     */
    public function verifyDomain(Request $request): JsonResponse
    {
        $tenant = \App\Tenancy\TenantContext::getTenant();
        if (!$tenant || empty($tenant->domain) || empty($tenant->domain_verification_token)) {
            return response()->json(['error' => 'No pending domain verification found for tenant.'], 422);
        }

        $records = @dns_get_record("_tableza-challenge.{$tenant->domain}", DNS_TXT) ?: [];
        $matched = false;
        foreach ($records as $record) {
            if (isset($record['txt']) && hash_equals($tenant->domain_verification_token, $record['txt'])) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return response()->json([
                'error' => 'DNS TXT verification record not found or mismatch.',
                'expected_record' => "_tableza-challenge.{$tenant->domain}",
            ], 422);
        }

        $tenant->update(['domain_verified_at' => now()]);

        return response()->json([
            'message' => 'Custom domain verified successfully.',
            'domain' => $tenant->domain,
            'verified_at' => $tenant->domain_verified_at->toIso8601String(),
        ]);
    }
}
