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
     * List reservations for active tenant.
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

        $reservations = $query->orderBy('reservation_date')->orderBy('reservation_time')->get();

        return response()->json($reservations);
    }

    /**
     * Create a new reservation with conflict detection.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['required', 'email'],
            'guest_phone' => ['nullable', 'string'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'reservation_date' => ['required', 'date'],
            'reservation_time' => ['required', 'string'],
            'special_requests' => ['nullable', 'string'],
        ]);

        // Conflict check if a specific table is requested
        if (!empty($validated['table_id'])) {
            $conflict = Reservation::where('table_id', $validated['table_id'])
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
    }

    /**
     * Update reservation status.
     */
    public function updateStatus(Request $request, Reservation $reservation): JsonResponse
    {
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

        return response()->json([
            'message' => 'Reservation status updated',
            'reservation' => $reservation->load('table'),
        ]);
    }
}
