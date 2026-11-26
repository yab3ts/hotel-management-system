<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\DigitalKey;
use App\Models\IotDevice;
use App\Models\Reservation;
use App\Models\SecurityAlert;
use App\Models\TableSession;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SecurityHardwareController extends Controller
{
    private const BLOCKED_IOT_DEFAULTS = [
        'admin', 'admin123', '1234', '12345', '123456', 'root', 'default', 'password', 'guest', 'ubnt', 'hikvision', '0000',
    ];

    /**
     * Register IoT device rejecting known factory default credentials (#115).
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['required', 'string', 'in:door_lock,tv,thermostat,camera,pos_terminal'],
            'device_identifier' => ['required', 'string', 'max:120'],
            'device_secret' => ['required', 'string', 'min:10'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if (in_array(strtolower(trim($validated['device_secret'])), self::BLOCKED_IOT_DEFAULTS, true)) {
            return response()->json([
                'error' => 'Rejected: Known factory default IoT device credentials are strictly forbidden (#115).',
            ], 422);
        }

        $device = IotDevice::create([
            'tenant_id' => TenantContext::getTenantId(),
            'branch_id' => $validated['branch_id'] ?? null,
            'name' => strip_tags($validated['name']),
            'device_type' => $validated['device_type'],
            'device_identifier' => strip_tags($validated['device_identifier']),
            'credential_hash' => Hash::make($validated['device_secret']),
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        AuditLog::record('iot_device_registered', $device, ['device_type' => $device->device_type]);

        return response()->json(['device' => $device], 201);
    }

    /**
     * Issue time-limited digital room/suite key; blocked after checkout or session close (#112).
     */
    public function issueKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_or_table_label' => ['required', 'string', 'max:60'],
            'table_session_id' => ['nullable', 'integer', 'exists:table_sessions,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
        ]);

        if (!empty($validated['table_session_id'])) {
            $session = TableSession::findOrFail($validated['table_session_id']);
            if ($session->status === 'closed') {
                return response()->json([
                    'error' => 'Cannot issue digital key for a closed/checked-out session.',
                ], 403);
            }
        }

        if (!empty($validated['reservation_id'])) {
            $reservation = Reservation::findOrFail($validated['reservation_id']);
            if (in_array($reservation->status, ['completed', 'cancelled'], true)) {
                return response()->json([
                    'error' => 'Cannot issue digital key after checkout or cancellation.',
                ], 403);
            }
        }

        $plainKey = 'dkey_' . bin2hex(random_bytes(16));
        $key = DigitalKey::create([
            'tenant_id' => TenantContext::getTenantId(),
            'table_session_id' => $validated['table_session_id'] ?? null,
            'reservation_id' => $validated['reservation_id'] ?? null,
            'room_or_table_label' => strip_tags($validated['room_or_table_label']),
            'key_token_hash' => hash('sha256', $plainKey),
            'expires_at' => now()->addHours(24),
        ]);

        AuditLog::record('digital_key_issued', $key, ['label' => $key->room_or_table_label]);

        return response()->json([
            'key_id' => $key->id,
            'key_token' => $plainKey,
            'expires_at' => $key->expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Door lock unlock endpoint with strict role/tenant checks, checkout expiry enforcement, and auditing (#111, #112).
     */
    public function unlockDoor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'integer', 'exists:iot_devices,id'],
            'key_token' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $tenant = TenantContext::getTenant();
        $device = IotDevice::where('id', $validated['device_id'])->where('is_active', true)->first();

        if (!$device || ($tenant && $device->tenant_id !== $tenant->id)) {
            AuditLog::record('door_unlock_denied', null, ['reason' => 'cross_tenant_or_missing_device', 'device_id' => $validated['device_id']]);
            return response()->json(['error' => 'Forbidden: Device does not belong to active tenant.'], 403);
        }

        $isAuthorizedStaff = $user && $user->hasRoleInTenant(['admin', 'manager', 'waiter', 'staff'], $tenant?->id);

        if (!$isAuthorizedStaff) {
            if (empty($validated['key_token'])) {
                AuditLog::record('door_unlock_denied', $device, ['reason' => 'unauthorized_role']);
                return response()->json(['error' => 'Forbidden: Staff role or active guest digital key required.'], 403);
            }

            $key = DigitalKey::where('key_token_hash', hash('sha256', $validated['key_token']))->first();
            if (!$key || !$key->isValid()) {
                AuditLog::record('door_unlock_denied', $device, ['reason' => 'expired_or_revoked_key']);
                return response()->json(['error' => 'Forbidden: Digital key is expired or revoked after checkout.'], 403);
            }
        }

        $device->update(['last_seen_at' => now()]);
        AuditLog::record('door_unlock_granted', $device, ['device_id' => $device->id, 'user_id' => $user?->id]);

        return response()->json([
            'unlocked' => true,
            'device' => $device->name,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Request sensitive payout/bank change (requires dual approval to block CEO fraud #123).
     */
    public function requestPayoutChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payout_iban' => ['required', 'string', 'min:10', 'max:34'],
            'payout_account_name' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $user = $request->user();
        $approval = ApprovalRequest::create([
            'tenant_id' => TenantContext::getTenantId(),
            'requested_by' => $user->id,
            'action' => 'payout_bank_change',
            'payload' => [
                'payout_iban' => strtoupper(preg_replace('/\s+/', '', $validated['payout_iban'])),
                'payout_account_name' => strip_tags($validated['payout_account_name']),
            ],
            'status' => 'pending',
        ]);

        AuditLog::record('payout_change_requested', $approval, ['requested_by' => $user->id]);
        SecurityAlert::raise('payout_change_pending', "Payout bank change requested by {$user->email}; awaiting second approver.", 'high', $user->id);

        return response()->json([
            'message' => 'Payout change queued. A second administrator must approve before activation.',
            'approval_id' => $approval->id,
            'status' => 'pending',
        ], 202);
    }

    /**
     * Second-approver sign-off for sensitive payout/bank changes (#123).
     * Strictly forbids self-approval by the original requester.
     */
    public function approveRequest(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $approver = $request->user();
        $tenant = TenantContext::getTenant();

        if ($tenant && $approval->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Forbidden: Approval request belongs to another tenant.'], 403);
        }

        if (!$approver->hasRoleInTenant(['admin', 'manager'], $approval->tenant_id)) {
            return response()->json(['error' => 'Forbidden: Approver does not hold admin or manager role in this tenant organization.'], 403);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['error' => 'Approval request has already been processed.'], 422);
        }

        if ((int) $approval->requested_by === (int) $approver->id) {
            AuditLog::record('dual_approval_self_attempt_blocked', $approval, ['user_id' => $approver->id]);
            return response()->json([
                'error' => 'Forbidden: Self-approval is prohibited. Dual-approver verification requires a different administrator (#123).',
            ], 403);
        }

        if ($approval->action === 'payout_bank_change' && $tenant) {
            $tenant->update([
                'payout_iban' => $approval->payload['payout_iban'],
                'payout_account_name' => $approval->payload['payout_account_name'],
            ]);
        }

        $approval->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'executed',
        ]);

        AuditLog::record('payout_details_changed', $approval, [
            'requested_by' => $approval->requested_by,
            'approved_by' => $approver->id,
        ]);

        return response()->json([
            'message' => 'Dual-approval confirmed and change executed.',
            'approval' => $approval,
        ]);
    }
}
