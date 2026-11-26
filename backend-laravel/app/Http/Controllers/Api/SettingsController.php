<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Get active venue configuration settings.
     */
    public function show(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Branch-ID') ?? 1;
        $branch = Branch::with('tenant')->find($branchId);

        if (!$branch) {
            $branch = Branch::with('tenant')->first();
        }

        return response()->json([
            'settings' => [
                'branch_id' => $branch?->id ?? 1,
                'branch_name' => $branch?->name ?? 'Café X Flagship',
                'tenant_name' => $branch?->tenant?->name ?? 'Café X Hospitality Group',
                'currency' => $branch?->currency ?? 'EUR',
                'tax_rate' => (float) ($branch?->tax_rate ?? 10.0),
                'service_charge' => (float) ($branch?->service_charge ?? 5.0),
                'is_qr_ordering_enabled' => (bool) ($branch?->is_qr_ordering_enabled ?? true),
                'session_timeout_minutes' => (int) ($branch?->session_timeout_minutes ?? 180),
                'qr_base_url' => config('app.qr_base_url', env('VITE_PUBLIC_URL', 'http://localhost:5174')),
                'demo_mode' => (bool) config('app.demo_mode', false),
                'require_table_pin' => false,
                'gateway_mode' => 'sandbox_simulator',
            ],
        ]);
    }

    /**
     * Update venue configuration settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'service_charge' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'is_qr_ordering_enabled' => ['nullable', 'boolean'],
            'session_timeout_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'qr_base_url' => ['nullable', 'string', 'url:http,https', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && (str_contains($value, '169.254.169.254') || str_contains(strtolower($value), 'metadata.google.internal'))) {
                    $fail("The {$attribute} cannot reference internal cloud metadata addresses.");
                }
            }],
        ]);

        $tenant = \App\Tenancy\TenantContext::getTenant();
        $user = $request->user();

        if ($user && $tenant && !$user->hasRoleInTenant(['admin', 'manager'], $tenant->id)) {
            return response()->json(['error' => 'Unauthorized. Admin or manager privileges required.'], 403);
        }

        $branchId = $request->header('X-Branch-ID');
        $branchQuery = Branch::query();
        if ($tenant) {
            $branchQuery->where('tenant_id', $tenant->id);
        }
        $branch = $branchId ? $branchQuery->find($branchId) : $branchQuery->first();

        if (!$branch) {
            return response()->json(['error' => 'Branch not found or belongs to another organization.'], 404);
        }

        if ($branch) {
            if (isset($validated['branch_name'])) $branch->name = $validated['branch_name'];
            if (isset($validated['currency'])) $branch->currency = strtoupper($validated['currency']);
            if (isset($validated['tax_rate'])) $branch->tax_rate = $validated['tax_rate'];
            if (isset($validated['service_charge'])) $branch->service_charge = $validated['service_charge'];
            if (isset($validated['is_qr_ordering_enabled'])) $branch->is_qr_ordering_enabled = $validated['is_qr_ordering_enabled'];
            if (isset($validated['session_timeout_minutes'])) $branch->session_timeout_minutes = $validated['session_timeout_minutes'];
            $branch->save();

            AuditLog::record('settings_updated', $branch, [
                'branch_id' => $branch->id,
                'updated_fields' => array_keys($validated),
            ]);
        }

        return response()->json([
            'message' => 'Venue settings updated successfully.',
            'settings' => $branch,
        ]);
    }
}
