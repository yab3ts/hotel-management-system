<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * List orders for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['items', 'table', 'user']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('order_type')) {
            $query->where('order_type', $type);
        }

        $orders = $query->latest()->get();

        return response()->json($orders);
    }

    /**
     * Create order with order items (Authoritative server pricing & availability checks).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'guest_name' => ['nullable', 'string', 'max:100'],
            'order_type' => ['nullable', 'string', 'in:dine_in,takeaway,delivery'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $subtotal = 0;
            $preparedItems = [];

            // 1. Authoritative server recomputation of menu prices & availability verification
            foreach ($validated['items'] as $itemInput) {
                $menuItem = MenuItem::findOrFail($itemInput['menu_item_id']);

                if (!$menuItem->is_available) {
                    abort(response()->json([
                        'error' => "Item '{$menuItem->name}' is currently marked sold out and cannot be ordered.",
                        'menu_item_id' => $menuItem->id,
                    ], 422));
                }

                $qty = (int) $itemInput['quantity'];
                $unitPrice = (float) $menuItem->price;
                $lineSubtotal = round($unitPrice * $qty, 2);
                $subtotal += $lineSubtotal;

                $preparedItems[] = [
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'subtotal' => $lineSubtotal,
                    'status' => 'placed',
                    'notes' => !empty($itemInput['notes']) ? strip_tags($itemInput['notes']) : null,
                ];
            }

            $tax = round($subtotal * 0.08, 2); // 8% tax
            $total = $subtotal + $tax;

            $sessionId = null;
            if (!empty($validated['table_id'])) {
                $table = \App\Models\RestaurantTable::find($validated['table_id']);
                if ($table) {
                    $table->update(['status' => 'occupied']);
                    $session = \App\Models\TableSession::where('table_id', $table->id)
                        ->where('status', '!=', 'closed')
                        ->latest()
                        ->first();
                    if (!$session) {
                        $session = \App\Models\TableSession::create([
                            'table_id' => $table->id,
                            'branch_id' => $table->branch_id,
                            'tenant_id' => $table->tenant_id,
                            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
                            'status' => 'active',
                            'opened_at' => now(),
                        ]);
                    }
                    $sessionId = $session->id;
                    $session->increment('subtotal', $subtotal);
                    $session->increment('tax', $tax);
                    $session->increment('total', $total);
                }
            }

            $tenantId = \App\Tenancy\TenantContext::getTenantId() ?? $request->user()?->current_tenant_id;
            do {
                $orderNumber = '#' . rand(1000, 9999) . '-' . strtoupper(bin2hex(random_bytes(2)));
            } while ($tenantId && Order::where('tenant_id', $tenantId)->where('order_number', $orderNumber)->exists());

            $order = Order::create([
                'user_id' => $request->user()?->id,
                'session_id' => $sessionId,
                'table_id' => $validated['table_id'] ?? null,
                'guest_name' => !empty($validated['guest_name']) ? strip_tags($validated['guest_name']) : 'Walk-in Guest',
                'order_number' => $orderNumber,
                'order_type' => $validated['order_type'] ?? 'dine_in',
                'status' => 'placed',
                'placed_at' => now(),
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'payment_status' => 'pending',
                'notes' => !empty($validated['notes']) ? strip_tags($validated['notes']) : null,
            ]);

            foreach ($preparedItems as $pItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $pItem['menu_item_id'],
                    'item_name' => $pItem['item_name'],
                    'unit_price' => $pItem['unit_price'],
                    'quantity' => $pItem['quantity'],
                    'subtotal' => $pItem['subtotal'],
                    'status' => 'placed',
                    'notes' => $pItem['notes'],
                ]);
            }

            // If dine-in and table provided, mark table as occupied
            if (!empty($validated['table_id'])) {
                RestaurantTable::find($validated['table_id'])?->update(['status' => 'occupied']);
            }

            // Automatically deduct recipe inventory stock inside transaction
            \App\Services\InventoryService::deductRecipeForOrder($order->load('items'));

            return response()->json($order->load(['items', 'table']), 201);
        });
    }

    /**
     * Update order status with strict lifecycle state machine validation.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:placed,accepted,preparing,ready,served,completed,cancelled'],
            'cancellation_reason' => ['nullable', 'string', 'min:3'],
        ]);

        $status = $validated['status'];
        $currentStatus = $order->status;

        // Strict order state machine
        $allowedTransitions = [
            'placed' => ['accepted', 'cancelled'],
            'accepted' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['served'],
            'served' => ['completed'],
            'completed' => [], // terminal
            'cancelled' => [], // terminal
            'pending' => ['placed', 'accepted', 'cancelled'], // legacy compatibility
        ];

        if (!in_array($status, $allowedTransitions[$currentStatus] ?? [])) {
            return response()->json([
                'error' => "Illegal order transition: cannot advance order from '{$currentStatus}' to '{$status}'.",
                'current_status' => $currentStatus,
                'requested_status' => $status,
                'allowed_next_states' => $allowedTransitions[$currentStatus] ?? [],
            ], 422);
        }

        if ($status === 'cancelled' && empty($validated['cancellation_reason'])) {
            return response()->json([
                'error' => 'Cancellation reason of at least 3 characters is strictly required.',
            ], 422);
        }

        $updates = ['status' => $status];

        switch ($status) {
            case 'accepted':
                $updates['accepted_at'] = now();
                break;
            case 'preparing':
                $updates['preparing_at'] = now();
                break;
            case 'ready':
                $updates['ready_at'] = now();
                break;
            case 'served':
                $updates['served_at'] = now();
                break;
            case 'completed':
                // Completed marks table delivery verified and bill settled
                break;
            case 'cancelled':
                $updates['cancelled_at'] = now();
                $updates['cancellation_reason'] = strip_tags($validated['cancellation_reason']);
                break;
        }

        $order->update($updates);

        // Update item statuses to match order progression
        if (in_array($status, ['preparing', 'ready', 'served', 'cancelled'])) {
            $order->items()->update(['status' => $status]);
        }

        if ($status === 'cancelled') {
            \App\Services\InventoryService::restoreRecipeForOrder($order->load('items'));
        }

        if (in_array($status, ['served', 'completed']) && $order->session_id) {
            $session = \App\Models\TableSession::find($order->session_id);
            if ($session && $session->status !== 'closed' && (float) $session->paid_amount >= (float) $session->total && (float) $session->total > 0) {
                $hasPending = Order::where('session_id', $session->id)
                    ->whereNotIn('status', ['served', 'completed', 'cancelled'])
                    ->exists();
                if (!$hasPending) {
                    $session->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);
                    if ($order->table && $order->table->status !== \App\Services\TableStateMachine::STATUS_CLEANING) {
                        \App\Services\TableStateMachine::transition(
                            $order->table,
                            \App\Services\TableStateMachine::STATUS_CLEANING,
                            'All orders served and bill fully settled'
                        );
                    }
                }
            }
        }

        return response()->json([
            'message' => "Order #{$order->order_number} status updated to {$order->status}",
            'order' => $order->load(['items', 'table']),
        ]);
    }

    /**
     * Mark single kitchen item as complete.
     */
    public function completeItem(OrderItem $item): JsonResponse
    {
        $item->update(['status' => 'ready']);

        // Check if all items in the order are ready
        $order = $item->order;
        $remaining = $order->items()->where('status', '!=', 'ready')->count();

        if ($remaining === 0) {
            $order->update(['status' => 'ready']);
        }

        return response()->json([
            'message' => "Dish {$item->item_name} marked as ready",
            'order_status' => $order->status,
        ]);
    }
}
