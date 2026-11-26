<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PrivacyController extends Controller
{
    /**
     * Export authenticated user's personal data (GDPR Article 15 / #84).
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $orders = Order::where('user_id', $user->id)->with('items')->get();
        $reservations = Reservation::where('user_id', $user->id)->get();

        AuditLog::record('gdpr_data_exported', $user, ['user_id' => $user->id]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'orders' => $orders,
            'reservations' => $reservations,
        ]);
    }

    /**
     * Anonymize and erase authenticated user's personal data (GDPR Article 17 / #84).
     * Preserves immutable financial totals while scrubbing PII.
     */
    public function erase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Invalid password confirmation.'], 401);
        }

        $anonymizedEmail = "erased_{$user->id}_" . bin2hex(random_bytes(4)) . '@anonymized.local';

        Reservation::where('user_id', $user->id)->update([
            'guest_name' => 'Redacted Guest',
            'guest_email' => $anonymizedEmail,
            'guest_phone' => null,
            'special_requests' => null,
        ]);

        Order::where('user_id', $user->id)->update([
            'guest_name' => 'Redacted Guest',
        ]);

        $user->tokens()->delete();
        $user->update([
            'name' => 'Erased User',
            'email' => $anonymizedEmail,
            'phone' => null,
            'avatar_url' => null,
            'is_active' => false,
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
        ]);

        AuditLog::record('gdpr_data_erased', null, ['erased_user_id' => $user->id]);

        return response()->json([
            'message' => 'Your personal data has been erased and anonymized in compliance with GDPR.',
        ]);
    }

    /**
     * List staff accounts scoped to the active tenant (#13).
     */
    public function listStaff(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();
        if (!$tenant) {
            return response()->json(['staff' => []]);
        }

        $staff = $tenant->users()->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role ?? 'customer',
                'is_active' => $u->is_active !== false,
                'mfa_enabled' => (bool) $u->mfa_enabled,
            ];
        });

        return response()->json(['staff' => $staff]);
    }

    /**
     * Activate or deactivate a staff account inside the active tenant (#12/#13).
     */
    public function updateStaffStatus(Request $request, User $user): JsonResponse
    {
        $tenant = TenantContext::getTenant();
        if (!$tenant || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Staff account not found in this tenant.'], 404);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update(['is_active' => $validated['is_active']]);
        if (!$validated['is_active']) {
            $user->tokens()->delete();
        }

        AuditLog::record('staff_status_updated', $user, [
            'target_user_id' => $user->id,
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'message' => $validated['is_active'] ? 'Account activated.' : 'Account disabled and sessions revoked.',
            'user_id' => $user->id,
            'is_active' => (bool) $user->is_active,
        ]);
    }

    /**
     * Remote wipe: revoke all active tokens for a staff member's lost/stolen device (#119).
     */
    public function revokeStaffTokens(Request $request, User $user): JsonResponse
    {
        $tenant = TenantContext::getTenant();
        if (!$tenant || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Staff account not found in this tenant.'], 404);
        }

        $count = $user->tokens()->delete();
        AuditLog::record('staff_tokens_wiped', $user, ['target_user_id' => $user->id, 'revoked_count' => $count]);

        return response()->json([
            'message' => 'All active tokens wiped for user.',
            'revoked_count' => $count,
        ]);
    }
}
