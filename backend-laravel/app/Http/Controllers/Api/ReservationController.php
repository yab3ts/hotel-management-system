<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    /**
     * List reservations for active tenant (logs alert if non-admin staff queries VIP guest records #30).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Reservation::with(['table', 'user']);

        if ($date = $request->query('date')) {
            $query->whereDate('reservation_date', $date);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('reservation_number', 'like', "%{$search}%");
            });
        }

        $reservations = $query->orderBy('reservation_date')->orderBy('reservation_time')->get();

        // Security (#30): Alert & audit when non-admin staff accesses VIP guest records
        $user = $request->user();
        $hasVip = $reservations->contains(fn ($r) => (bool) $r->is_vip);
        if ($hasVip && $user && !$user->isAdmin()) {
            \App\Models\AuditLog::record('vip_guest_record_viewed', null, [
                'staff_user_id' => $user->id,
                'staff_email' => $user->email,
                'vip_count' => $reservations->where('is_vip', true)->count(),
            ], $user->id);

            \App\Models\SecurityAlert::raise(
                'vip_record_accessed',
                "Staff user {$user->email} accessed VIP guest reservation record(s).",
                'medium',
                $user->id,
                ['search' => $search]
            );
        }

        return response()->json($reservations);
    }

    /**
     * Create a new reservation with atomic locking (#50) and date validation (#53).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['required', 'email'],
            'guest_phone' => ['nullable', 'string', 'max:50'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:50'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'reservation_time' => ['required', 'string', 'max:20'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        // Sanitize user-provided text against stored XSS (#33)
        $validated['guest_name'] = strip_tags($validated['guest_name']);
        if (!empty($validated['special_requests'])) {
            $validated['special_requests'] = strip_tags($validated['special_requests']);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $request) {
            // Conflict check with row-level lock to prevent double booking (#50)
            if (!empty($validated['table_id'])) {
                $table = RestaurantTable::where('id', $validated['table_id'])->lockForUpdate()->first();
                if (!$table) {
                    return response()->json([
                        'error' => 'Table not found within active tenant.',
                    ], 404);
                }

                $conflict = Reservation::where('table_id', $table->id)
                    ->whereDate('reservation_date', $validated['reservation_date'])
                    ->where('reservation_time', $validated['reservation_time'])
                    ->whereIn('status', ['confirmed', 'seated', 'pending'])
                    ->exists();

                if ($conflict) {
                    return response()->json([
                        'error' => 'This table is already reserved for the selected date and time.',
                    ], 422);
                }
            }

            $reservationNumber = 'RES-' . strtoupper(Str::random(6));

            $reservation = Reservation::create([
                ...$validated,
                'user_id' => $request->user()?->id,
                'reservation_number' => $reservationNumber,
                'status' => 'confirmed',
            ]);

            return response()->json([
                'message' => 'Reservation confirmed successfully',
                'reservation' => $reservation->load('table'),
            ], 201);
        });
    }

    /**
     * Update reservation status (enforces tenant scope against IDOR #18).
     */
    public function updateStatus(Request $request, Reservation $reservation): JsonResponse
    {
        $tenantId = \App\Tenancy\TenantContext::getTenantId();
        if ($tenantId && $reservation->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Forbidden: Reservation belongs to another tenant.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,confirmed,seated,completed,cancelled'],
        ]);

        $reservation->update($validated);

        // If seated, mark table as occupied; if completed/cancelled, free table
        if ($reservation->table_id) {
            $table = RestaurantTable::find($reservation->table_id);
            if ($table) {
                if ($validated['status'] === 'seated') {
                    $table->update(['status' => 'occupied']);
                } elseif (in_array($validated['status'], ['completed', 'cancelled'])) {
                    $table->update(['status' => 'available']);
                }
            }
        }

        // Security (#112): Automatically revoke digital keys after guest checkout or cancellation
        if (in_array($validated['status'], ['completed', 'cancelled'], true)) {
            \App\Models\DigitalKey::where('reservation_id', $reservation->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        return response()->json([
            'message' => 'Reservation status updated',
            'reservation' => $reservation->load('table'),
        ]);
    }
}
