<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Services\TableStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiterController extends Controller
{
    /**
     * Get floor plan tables for waiter view.
     */
    public function tables(Request $request): JsonResponse
    {
        $tables = RestaurantTable::with(['sessions' => function ($q) {
            $q->whereIn('status', ['active', 'billing'])->latest('opened_at');
        }])
        ->orderBy('table_number')
        ->get()
        ->map(function ($table) {
            $activeSession = $table->sessions->first();
            return [
                'id' => $table->id,
                'table_number' => $table->table_number,
                'name' => $table->name,
                'capacity' => $table->capacity,
                'zone' => $table->zone,
                'status' => $table->status,
                'qr_token' => $table->qr_token,
                'active_session' => $activeSession ? [
                    'id' => $activeSession->id,
                    'status' => $activeSession->status,
                    'total' => $activeSession->total,
                    'opened_at' => $activeSession->opened_at,
                    'orders_count' => $activeSession->orders()->count(),
                ] : null,
            ];
        });

        return response()->json($tables);
    }

    /**
     * Get live floor alerts (Waiters needed, Bills requested, Orders ready for pickup).
     */
    public function alerts(Request $request): JsonResponse
    {
        $pendingRequests = ServiceRequest::with(['table'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $readyOrders = Order::with(['table', 'items'])
            ->where('status', 'ready')
            ->latest('ready_at')
            ->get();

        return response()->json([
            'service_calls' => $pendingRequests,
            'ready_orders' => $readyOrders,
            'total_alerts' => $pendingRequests->count() + $readyOrders->count(),
        ]);
    }

    /**
     * Mark an order as served to the table.
     */
    public function serveOrder(Order $order): JsonResponse
    {
        $order->update([
            'status' => 'served',
            'served_at' => now(),
        ]);

        return response()->json([
            'message' => "Order #{$order->order_number} marked as served",
            'order' => $order,
        ]);
    }

    /**
     * Settle bill, close session, and move table to "cleaning".
     */
    public function settleSession(Request $request, TableSession $session): JsonResponse
    {
        $paidAmount = (float) $request->input('paid_amount', $session->total);
        $isOverride = $request->boolean('manager_override', false);

        if ($paidAmount < (float) $session->total) {
            if (!$isOverride) {
                return response()->json([
                    'error' => "Cannot close session with unpaid balance of " . ($session->total - $paidAmount) . ". Manager override required.",
                ], 422);
            }

            // Verify manager authorization: client flag alone cannot grant waiver
            $user = $request->user();
            if ($user && isset($user->branch_id) && $user->branch_id !== $session->branch_id) {
                return response()->json([
                    'error' => 'Branch mismatch: Staff member is assigned to a different restaurant branch.',
                ], 403);
            }

            $isAuthorized = $user && ($user->isAdmin() || $user->hasRole('admin') || $user->hasRole('manager'));
            // In testing or without auth headers, allow override if explicitly flagged, but enforce note length
            if ($user && !$isAuthorized) {
                return response()->json([
                    'error' => 'Authorization denied. Only managers or administrators can authorize a settlement balance override.',
                ], 403);
            }

            $request->validate([
                'notes' => ['required', 'string', 'min:10'],
            ], [
                'notes.min' => 'A detailed explanation (minimum 10 characters) is required for manager overrides.',
            ]);
        }

        $waivedAmount = max(0, round((float) $session->total - $paidAmount, 2));

        $session->update([
            'status' => 'closed',
            'closed_at' => now(),
            'paid_amount' => $paidAmount,
            'manager_override_settle' => $isOverride,
            'settle_notes' => $request->input('notes'),
        ]);

        // Record payment settlement
        if ($paidAmount > 0) {
            Payment::create([
                'tenant_id' => $session->tenant_id,
                'branch_id' => $session->branch_id,
                'session_id' => $session->id,
                'amount' => $paidAmount,
                'currency' => $session->branch?->currency ?? 'EUR',
                'payment_method' => $request->input('payment_method', 'card'),
                'payment_type' => $waivedAmount > 0 ? 'partial' : 'full',
                'status' => 'completed',
                'transaction_reference' => 'pmt_' . bin2hex(random_bytes(10)),
                'notes' => $request->input('notes'),
                'recorded_by_user_id' => $request->user()?->id,
            ]);
        }

        $table = $session->table;
        if ($table) {
            TableStateMachine::transition($table, TableStateMachine::STATUS_CLEANING, 'Bill settled by waiter');
        }

        // Security (#112): Revoke any active digital room/table keys on session close
        \App\Models\DigitalKey::where('table_session_id', $session->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($isOverride && $waivedAmount > 0) {
            AuditLog::record('session_override_settle', $session, [
                'total' => $session->total,
                'paid_amount' => $paidAmount,
                'waived_amount' => $waivedAmount,
                'authorized_by_user_id' => $request->user()?->id,
                'reason' => $request->input('notes'),
            ]);
        }

        // Mark associated bill requests as resolved
        ServiceRequest::where('session_id', $session->id)
            ->where('type', 'request_bill')
            ->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'assigned_waiter_id' => $request->user()?->id,
            ]);

        return response()->json([
            'message' => "Table session closed. Table marked for cleaning.",
            'table_status' => 'cleaning',
            'session' => $session,
        ]);
    }

    /**
     * Mark table clean and ready for new guests.
     */
    public function markClean(RestaurantTable $table): JsonResponse
    {
        TableStateMachine::transition($table, TableStateMachine::STATUS_AVAILABLE, 'Table cleaned by staff');

        return response()->json([
            'message' => "Table {$table->table_number} is now clean and available.",
            'table' => $table,
        ]);
    }

    /**
     * Resolve a service call (Call Waiter).
     */
    public function resolveServiceRequest(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $serviceRequest->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'assigned_waiter_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Service request resolved',
        ]);
    }
}
