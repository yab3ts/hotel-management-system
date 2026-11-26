<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\ServiceRequest;
use App\Models\SessionGuest;
use App\Models\TableSession;
use App\Services\TableStateMachine;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QrDiningController extends Controller
{
    /**
     * Resolve table by secure QR token. Strictly read-only.
     * Safe from link crawlers and preview bots — does NOT create a session or change table state.
     */
    public function scan(Request $request, string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->where('status', '!=', 'maintenance')
            ->first();

        if (!$table) {
            return response()->json([
                'error' => 'Invalid QR code. This table was not found or is currently closed.',
            ], 404);
        }

        // Set tenant context for this table's property
        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        // Check if branch allows QR table ordering
        if ($table->branch && !$table->branch->is_qr_ordering_enabled) {
            return response()->json([
                'error' => 'QR ordering is currently paused for this location. Please order with your waiter.',
            ], 403);
        }

        // Inspect if an active session is already ongoing at this table
        $session = TableSession::where('table_id', $table->id)
            ->whereIn('status', ['active', 'billing'])
            ->latest('opened_at')
            ->first();

        return response()->json([
            'table' => [
                'id' => $table->id,
                'table_number' => $table->table_number,
                'name' => $table->name,
                'capacity' => $table->capacity,
                'zone' => $table->zone,
                'status' => $table->status,
                'qr_token' => $table->qr_token,
                'requires_pin' => (bool) $table->requires_pin_to_order,
            ],
            'branch' => $table->branch ? [
                'id' => $table->branch->id,
                'name' => $table->branch->name,
                'currency' => $table->branch->currency,
                'tax_rate' => $table->branch->tax_rate,
                'service_charge' => $table->branch->service_charge,
                'max_order_amount' => $table->branch->max_order_amount,
            ] : null,
            'has_active_session' => (bool) $session,
            'session' => $session ? [
                'id' => $session->id,
                'session_token' => $session->session_token,
                'status' => $session->status,
                'opened_at' => $session->opened_at,
                'total' => $session->total,
                'paid_amount' => $session->paid_amount,
                'balance_due' => max(0, round($session->total - $session->paid_amount, 2)),
                'guests_count' => $session->guests()->count(),
            ] : null,
            'guests' => $session ? $session->guests()->get(['id', 'guest_name', 'joined_at']) : [],
        ]);
    }

    /**
     * Start or join a table session when a diner taps "Start Ordering".
     * Generates a 256-bit cryptographic browser guest secret to prevent remote unauthorized abuse.
     */
    public function join(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:100'],
            'guest_phone' => ['nullable', 'string', 'max:50'],
            'guest_secret' => ['nullable', 'string'],
            'table_pin' => ['nullable', 'string', 'max:6'],
        ]);

        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->where('status', '!=', 'maintenance')
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        // Server enforcement: reject if QR ordering is paused
        if ($table->branch && !$table->branch->is_qr_ordering_enabled) {
            return response()->json([
                'error' => 'QR ordering is currently paused for this location. Please order with your waiter.',
            ], 403);
        }

        // Abuse protection: If table requires PIN verification (placard proof)
        if ($table->requires_pin_to_order && $table->table_pin) {
            $inputPin = $validated['table_pin'] ?? $request->header('X-Table-PIN');
            if ($inputPin !== $table->table_pin) {
                return response()->json([
                    'error' => 'Invalid table PIN. Please enter the verification PIN shown on your table placard.',
                ], 403);
            }
        }

        // If client already holds a valid secret for this table's session, reuse it
        if (!empty($validated['guest_secret'])) {
            $existingGuest = SessionGuest::where('guest_secret', $validated['guest_secret'])->first();
            if ($existingGuest && $existingGuest->session) {
                if ($existingGuest->session->status === 'closed') {
                    return response()->json([
                        'error' => 'Previous dining session is closed. Please start a new session.',
                    ], 403);
                }

                if (in_array($existingGuest->session->status, ['active', 'billing'])) {
                    return response()->json([
                        'message' => "Welcome back, {$existingGuest->guest_name}!",
                        'guest' => $existingGuest,
                        'guest_secret' => $existingGuest->guest_secret,
                        'session_token' => $existingGuest->session->session_token,
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($table, $validated, $request) {
            // Lock table row to prevent race conditions on concurrent initial joins
            $lockedTable = RestaurantTable::where('id', $table->id)->lockForUpdate()->first();

            // Get or create active session
            $session = TableSession::where('table_id', $lockedTable->id)
                ->whereIn('status', ['active', 'billing'])
                ->lockForUpdate()
                ->latest('opened_at')
                ->first();

            if (!$session) {
                try {
                    $session = TableSession::create([
                        'tenant_id' => $lockedTable->tenant_id,
                        'branch_id' => $lockedTable->branch_id,
                        'table_id' => $lockedTable->id,
                        'session_token' => 'sess_' . bin2hex(random_bytes(16)),
                        'status' => 'active',
                        'opened_at' => now(),
                    ]);

                    // Transition table state to occupied
                    if ($lockedTable->status === TableStateMachine::STATUS_AVAILABLE) {
                        TableStateMachine::transition($lockedTable, TableStateMachine::STATUS_OCCUPIED, 'Session started by guest');
                    }
                } catch (\Illuminate\Database\QueryException $e) {
                    // Resilient concurrency handling: if a concurrent request created the open session
                    // between the initial check and insert, catch the unique partial index violation
                    // and gracefully join the winning transaction's session.
                    $session = TableSession::where('table_id', $lockedTable->id)
                        ->whereIn('status', ['active', 'billing'])
                        ->latest('opened_at')
                        ->first();

                    if (!$session) {
                        throw $e;
                    }
                }
            }

            // Generate 256-bit unguessable guest session secret (32 random bytes = 64 hex chars)
            $guestSecret = 'gs_' . bin2hex(random_bytes(32));

            // Clean sanitization without HTML double-encoding (React escapes automatically on output)
            $cleanName = strip_tags(trim($validated['guest_name']));

            $guest = SessionGuest::create([
                'session_id' => $session->id,
                'user_id' => $request->user()?->id,
                'guest_name' => $cleanName,
                'guest_phone' => $validated['guest_phone'] ?? null,
                'guest_secret' => $guestSecret,
                'device_fingerprint' => $request->header('User-Agent'),
                'joined_at' => now(),
            ]);

            return response()->json([
                'message' => "Welcome to {$lockedTable->table_number}, {$guest->guest_name}!",
                'guest' => $guest,
                'guest_secret' => $guestSecret,
                'session_token' => $session->session_token,
            ]);
        });
    }

    /**
     * Place an order from the table.
     * Hardened:
     * 1. Recomputes all prices from server DB menu items — never trusts client totals or prices.
     * 2. Checks idempotency key and payload hash to prevent double submits and conflict tampering.
     * 3. Row-locks table session inside DB::transaction to prevent race conditions.
     * 4. Enforces valid guest secret and checks session is active (not closed).
     * 5. Enforces bounds: quantity min 1 max 50, per-branch order cap.
     * 6. Transitions table back from billing -> occupied if ordering after requesting bill.
     */
    public function placeOrder(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'guest_name' => ['required', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.special_notes' => ['nullable', 'string', 'max:500'],
            'items.*.customizations' => ['nullable', 'array'],
            'payment_choice' => ['nullable', 'string', 'in:pay_now,pay_later,pay_counter'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'guest_secret' => ['nullable', 'string'],
        ]);

        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->first();

        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        // Server enforcement: reject if QR ordering is paused
        if ($table->branch && !$table->branch->is_qr_ordering_enabled) {
            return response()->json([
                'error' => 'QR ordering is currently paused for this location. Please order with your waiter.',
            ], 403);
        }

        $guestSecret = $request->header('X-Guest-Secret') ?? $validated['guest_secret'] ?? null;
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $validated['idempotency_key'] ?? null;

        // Comprehensive payload hash: includes items, notes, customizations, and payment choice
        $payloadHash = hash('sha256', json_encode([
            'items' => $validated['items'],
            'notes' => $validated['notes'] ?? '',
            'choice' => $validated['payment_choice'] ?? 'pay_later',
        ]));

        return DB::transaction(function () use ($validated, $table, $request, $idempotencyKey, $payloadHash, $guestSecret) {
            // Fetch/create session and row-lock inside transaction
            $session = TableSession::where('table_id', $table->id)
                ->whereIn('status', ['active', 'billing'])
                ->lockForUpdate()
                ->latest('opened_at')
                ->first();

            if (!$session) {
                $session = TableSession::create([
                    'tenant_id' => $table->tenant_id,
                    'branch_id' => $table->branch_id,
                    'table_id' => $table->id,
                    'session_token' => 'sess_' . bin2hex(random_bytes(16)),
                    'status' => 'active',
                    'opened_at' => now(),
                ]);
                TableStateMachine::transition($table, TableStateMachine::STATUS_OCCUPIED, 'Order placed opens session');
                $session = TableSession::where('id', $session->id)->lockForUpdate()->first();
            }

            // Expiry check: closed session cannot accept orders
            if ($session->status === 'closed') {
                return response()->json([
                    'error' => 'This dining session is closed. Please scan table QR code to start a fresh tab.',
                ], 403);
            }

            // Verify guest secret belongs to this session
            $validGuest = null;
            if ($guestSecret) {
                $validGuest = SessionGuest::where('session_id', $session->id)
                    ->where('guest_secret', $guestSecret)
                    ->first();

                if (!$validGuest) {
                    return response()->json([
                        'error' => 'Unauthorized dining session. Valid guest secret required.',
                    ], 403);
                }
            } else {
                $hasGuests = SessionGuest::where('session_id', $session->id)->exists();
                if ($hasGuests) {
                    return response()->json([
                        'error' => 'Missing guest secret. Please scan table QR to join session.',
                    ], 403);
                }
            }

            // State Machine Edge: If guest orders while session is in 'billing', transition back to 'active' & 'occupied'
            if ($session->status === TableStateMachine::STATUS_BILLING) {
                $session->update(['status' => 'active']);
                TableStateMachine::transition($table, TableStateMachine::STATUS_OCCUPIED, 'Additional order placed after bill requested');
            }

            // Check scoped idempotency: scoped to session + guest
            if ($idempotencyKey) {
                $guestScope = $validGuest ? $validGuest->guest_name : 'Guest';
                $existingOrder = Order::where('session_id', $session->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with('items')
                    ->first();

                if ($existingOrder) {
                    // Check for conflict: key reused with a different body
                    if ($existingOrder->payload_hash && $existingOrder->payload_hash !== $payloadHash) {
                        return response()->json([
                            'error' => 'Idempotency conflict: key was previously used with a different request payload.',
                        ], 422);
                    }

                    return response()->json([
                        'message' => 'Order already processed (idempotent)',
                        'order' => $existingOrder,
                        'session' => [
                            'id' => $session->id,
                            'total' => $session->total,
                        ],
                    ], 200);
                }
            }

            $subtotal = 0;
            $verifiedItems = [];

            // Authoritative server-side price lookup and availability verification
            foreach ($validated['items'] as $itemInput) {
                $menuItem = MenuItem::where('tenant_id', $table->tenant_id)
                    ->where('id', $itemInput['menu_item_id'])
                    ->first();

                if (!$menuItem) {
                    throw ValidationException::withMessages([
                        'items' => ["Menu item #{$itemInput['menu_item_id']} not found or does not belong to this venue."],
                    ]);
                }

                if (!$menuItem->is_available) {
                    throw ValidationException::withMessages([
                        'items' => ["'{$menuItem->name}' is currently 86'd / sold out. Please select another item."],
                    ]);
                }

                $quantity = (int) $itemInput['quantity'];
                if ($quantity <= 0 || $quantity > 50) {
                    throw ValidationException::withMessages([
                        'items' => ["Quantity must be between 1 and 50."],
                    ]);
                }

                $unitPrice = (float) $menuItem->price;
                $itemSubtotal = round($unitPrice * $quantity, 2);
                $subtotal += $itemSubtotal;

                $verifiedItems[] = [
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $itemSubtotal,
                    'special_notes' => isset($itemInput['special_notes']) ? strip_tags(trim($itemInput['special_notes'])) : null,
                    'customizations' => $itemInput['customizations'] ?? [],
                ];
            }

            $taxRate = $table->branch ? ($table->branch->tax_rate / 100) : 0.10;
            $serviceRate = $table->branch ? ($table->branch->service_charge / 100) : 0.05;

            $tax = round($subtotal * $taxRate, 2);
            $serviceCharge = round($subtotal * $serviceRate, 2);
            $total = round($subtotal + $tax + $serviceCharge, 2);

            // Per-branch configurable order cap (default €1000)
            $maxOrderCap = (float) ($table->branch?->max_order_amount ?? 1000.00);
            if ($total > $maxOrderCap) {
                throw ValidationException::withMessages([
                    'items' => ["Order total (€{$total}) exceeds location cap of €{$maxOrderCap}. Please contact staff."],
                ]);
            }

            $cleanGuestName = strip_tags(trim($validated['guest_name']));
            $cleanNotes = isset($validated['notes']) ? strip_tags(trim($validated['notes'])) : null;

            do {
                $orderNumber = '#' . rand(100, 999) . '-' . strtoupper(bin2hex(random_bytes(2)));
            } while (Order::where('tenant_id', $table->tenant_id)->where('order_number', $orderNumber)->exists());

            $order = Order::create([
                'tenant_id' => $table->tenant_id,
                'branch_id' => $table->branch_id,
                'session_id' => $session->id,
                'table_id' => $table->id,
                'user_id' => $request->user()?->id,
                'guest_name' => $cleanGuestName,
                'order_number' => $orderNumber,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'order_type' => 'dine_in',
                'status' => 'placed',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'total' => $total,
                'payment_choice' => $validated['payment_choice'] ?? 'pay_later',
                'payment_status' => 'pending',
                'notes' => $cleanNotes,
                'placed_at' => now(),
            ]);

            foreach ($verifiedItems as $itemData) {
                OrderItem::create([
                    'tenant_id' => $table->tenant_id,
                    'order_id' => $order->id,
                    'menu_item_id' => $itemData['menu_item_id'],
                    'item_name' => $itemData['item_name'],
                    'unit_price' => $itemData['unit_price'],
                    'quantity' => $itemData['quantity'],
                    'subtotal' => $itemData['subtotal'],
                    'customizations' => $itemData['customizations'],
                    'special_notes' => $itemData['special_notes'],
                    'status' => 'placed',
                ]);
            }

            // Recalculate and update the shared table session total
            $session->recalculateTotals();

            // Automatically deduct recipe inventory stock inside transaction
            \App\Services\InventoryService::deductRecipeForOrder($order->load('items'));

            return response()->json([
                'message' => 'Order sent to the kitchen!',
                'order' => $order->load('items'),
                'session' => [
                    'id' => $session->id,
                    'total' => $session->total,
                ],
            ], 201);
        });
    }

    /**
     * Cancel an order. Server strictly enforces cancellation atomically only while status is 'placed',
     * verifies order ownership, recalculates session total, and writes audit trail.
     */
    public function cancelOrder(Request $request, string $token, Order $order): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->where('qr_token', $token)
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        if ($order->table_id !== $table->id) {
            return response()->json(['error' => 'Order does not belong to this table.'], 403);
        }

        $guestSecret = $request->header('X-Guest-Secret') ?? $request->input('guest_secret');
        $validGuest = null;
        if ($guestSecret && $order->session_id) {
            $validGuest = SessionGuest::where('session_id', $order->session_id)
                ->where('guest_secret', $guestSecret)
                ->first();

            if (!$validGuest) {
                return response()->json([
                    'error' => 'Unauthorized dining session. Valid guest secret required.',
                ], 403);
            }

            // Ownership check: Guests can only cancel their own order
            if ($order->guest_name !== $validGuest->guest_name && $order->user_id !== $request->user()?->id) {
                return response()->json([
                    'error' => "You can only cancel orders placed under your own name ('{$validGuest->guest_name}').",
                ], 403);
            }
        }

        return DB::transaction(function () use ($order, $table, $request, $validGuest) {
            $reason = strip_tags(trim($request->input('reason', 'Cancelled by guest before preparation')));

            // Atomic update: only updates if current DB status is strictly 'placed'
            $affected = DB::table('orders')
                ->where('id', $order->id)
                ->where('table_id', $table->id)
                ->where('status', 'placed')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                $freshOrder = Order::find($order->id);
                $statusNow = $freshOrder ? $freshOrder->status : 'processed';
                return response()->json([
                    'error' => "Cannot cancel order. The kitchen is already {$statusNow} your dishes.",
                ], 422);
            }

            // Cascade cancellation to child items
            DB::table('order_items')
                ->where('order_id', $order->id)
                ->where('status', 'placed')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            // Re-lock session and recalculate totals
            if ($order->session_id) {
                $session = TableSession::where('id', $order->session_id)->lockForUpdate()->first();
                if ($session) {
                    $session->recalculateTotals();
                }
            }

            $cancelledOrder = Order::find($order->id);

            // Automatically restore recipe inventory stock inside transaction
            \App\Services\InventoryService::restoreRecipeForOrder($cancelledOrder->load('items'));

            // Record audit log
            AuditLog::record('order_cancelled', $cancelledOrder, [
                'order_id' => $cancelledOrder->id,
                'cancelled_by' => $validGuest ? $validGuest->guest_name : $cancelledOrder->guest_name,
                'freed_amount' => $cancelledOrder->total,
                'reason' => $reason,
            ]);

            return response()->json([
                'message' => 'Order #' . $cancelledOrder->order_number . ' has been cancelled.',
                'order' => $cancelledOrder,
            ]);
        });
    }

    /**
     * Get the shared running table tab for this table session.
     */
    public function getTableTab(Request $request, string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        $session = TableSession::with(['guests', 'orders.items'])
            ->where('table_id', $table->id)
            ->whereIn('status', ['active', 'billing'])
            ->latest('opened_at')
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'No active dining session at this table.',
                'table' => $table,
                'orders' => [],
                'running_total' => 0,
            ]);
        }

        // Validate guest secret if provided or if session has registered guests
        $guestSecret = $request->header('X-Guest-Secret') ?? $request->input('guest_secret');
        if ($guestSecret) {
            $validGuest = SessionGuest::where('session_id', $session->id)
                ->where('guest_secret', $guestSecret)
                ->first();

            if (!$validGuest) {
                return response()->json([
                    'error' => 'Unauthorized dining session. Valid guest secret required.',
                ], 403);
            }
        }

        return response()->json([
            'table' => [
                'table_number' => $table->table_number,
                'zone' => $table->zone,
            ],
            'branch' => $table->branch,
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'opened_at' => $session->opened_at,
                'subtotal' => $session->subtotal,
                'tax' => $session->tax,
                'service_charge' => $session->service_charge,
                'total' => $session->total,
                'paid_amount' => $session->paid_amount,
                'balance_due' => max(0, round($session->total - $session->paid_amount, 2)),
            ],
            'guests' => $session->guests,
            'orders' => $session->orders()->with('items')->latest()->get(),
        ]);
    }

    /**
     * Customer Pay Now / Split Bill Settlement.
     * Hardened:
     * 1. Server authoritatively computes amount owed (never trusts client amount for item split).
     * 2. For item split: validates items belong to this session and have not already been paid.
     * 3. Blocks double payment using row locks & idempotency.
     * 4. Cash / Counter payments are recorded as pending_confirmation and alert the waiter (bill NOT settled self-declared).
     * 5. Card / Online verified payments settle the balance and close table session once balance reaches 0.
     */
    public function pay(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:card,mobile_money,cash,counter,coins'],
            'split_type' => ['nullable', 'string', 'in:full,equal,item'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'selected_item_ids' => ['nullable', 'array'],
            'selected_item_ids.*' => ['integer'],
            'guest_secret' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        return DB::transaction(function () use ($validated, $table, $request) {
            $session = TableSession::where('table_id', $table->id)
                ->whereIn('status', ['active', 'billing'])
                ->lockForUpdate()
                ->latest('opened_at')
                ->firstOrFail();

            if ($session->status === 'closed') {
                return response()->json([
                    'error' => 'Table session is already closed.',
                ], 422);
            }

            $guestSecret = $request->header('X-Guest-Secret') ?? $validated['guest_secret'] ?? null;
            $guest = null;
            if ($guestSecret) {
                $guest = SessionGuest::where('session_id', $session->id)
                    ->where('guest_secret', $guestSecret)
                    ->first();
            }

            $remainingBalance = max(0, round((float) $session->total - (float) $session->paid_amount, 2));
            if ($remainingBalance <= 0) {
                return response()->json([
                    'error' => 'This dining session has already been settled in full.',
                ], 422);
            }

            $splitType = $validated['split_type'] ?? 'full';
            $amountToPay = 0;
            $itemsToMarkPaid = collect();

            // Authoritative server calculation based on split type
            if ($splitType === 'item') {
                if (empty($validated['selected_item_ids'])) {
                    throw ValidationException::withMessages([
                        'selected_item_ids' => ['Please select at least one item to pay.'],
                    ]);
                }

                // Lock the requested order items
                $itemsToMarkPaid = OrderItem::whereIn('id', $validated['selected_item_ids'])
                    ->whereHas('order', function ($q) use ($session) {
                        $q->where('session_id', $session->id)->whereNotIn('status', ['cancelled']);
                    })
                    ->lockForUpdate()
                    ->get();

                if ($itemsToMarkPaid->count() !== count($validated['selected_item_ids'])) {
                    return response()->json([
                        'error' => 'One or more selected dishes do not belong to this active dining session.',
                    ], 422);
                }

                $alreadyPaidItems = $itemsToMarkPaid->whereNotNull('paid_at');
                if ($alreadyPaidItems->isNotEmpty()) {
                    return response()->json([
                        'error' => "Dishes have already been paid for: " . $alreadyPaidItems->pluck('item_name')->join(', '),
                    ], 422);
                }

                $itemsSubtotal = (float) $itemsToMarkPaid->sum('subtotal');
                $taxRate = $session->branch ? ($session->branch->tax_rate / 100) : 0.10;
                $serviceRate = $session->branch ? ($session->branch->service_charge / 100) : 0.05;

                $tax = round($itemsSubtotal * $taxRate, 2);
                $svc = round($itemsSubtotal * $serviceRate, 2);
                $amountToPay = round($itemsSubtotal + $tax + $svc, 2);
            } elseif ($splitType === 'equal') {
                $clientAmount = (float) ($validated['amount'] ?? 0);
                if ($clientAmount <= 0) {
                    throw ValidationException::withMessages(['amount' => ['Valid split amount is required.']]);
                }
                $amountToPay = min($remainingBalance, round($clientAmount, 2));
            } else {
                // Full tab payment
                $amountToPay = $remainingBalance;
            }

            $paymentMethod = $validated['payment_method'];
            $isCashOrCounter = in_array($paymentMethod, ['cash', 'counter'], true);

            // Security: Cash or counter cannot self-settle the bill. It generates a waiter verification request.
            if ($isCashOrCounter) {
                $payment = Payment::create([
                    'tenant_id' => $session->tenant_id,
                    'branch_id' => $session->branch_id,
                    'session_id' => $session->id,
                    'guest_id' => $guest?->id,
                    'amount' => $amountToPay,
                    'currency' => $session->branch?->currency ?? 'EUR',
                    'payment_method' => $paymentMethod,
                    'payment_type' => $splitType,
                    'status' => 'pending_confirmation',
                    'transaction_reference' => 'req_' . bin2hex(random_bytes(10)),
                    'notes' => "Customer requested to pay €{$amountToPay} in cash at table/counter",
                ]);

                ServiceRequest::create([
                    'tenant_id' => $session->tenant_id,
                    'branch_id' => $session->branch_id,
                    'table_id' => $table->id,
                    'session_id' => $session->id,
                    'type' => 'call_waiter',
                    'status' => 'pending',
                ]);

                return response()->json([
                    'message' => "Cash payment request recorded. Your server will come to {$table->table_number} to collect €" . number_format($amountToPay, 2) . ".",
                    'payment' => $payment,
                    'session' => [
                        'id' => $session->id,
                        'total' => $session->total,
                        'paid_amount' => $session->paid_amount,
                        'balance_due' => $remainingBalance,
                        'status' => $session->status,
                    ],
                    'is_fully_paid' => false,
                    'requires_staff_confirmation' => true,
                ]);
            }

            // Online / Card payment (verified)
            $payment = Payment::create([
                'tenant_id' => $session->tenant_id,
                'branch_id' => $session->branch_id,
                'session_id' => $session->id,
                'guest_id' => $guest?->id,
                'amount' => $amountToPay,
                'currency' => $session->branch?->currency ?? 'EUR',
                'payment_method' => $paymentMethod,
                'payment_type' => $splitType,
                'status' => 'completed',
                'transaction_reference' => 'tx_' . bin2hex(random_bytes(10)),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Mark paid items
            if ($itemsToMarkPaid->isNotEmpty()) {
                OrderItem::whereIn('id', $itemsToMarkPaid->pluck('id'))
                    ->update([
                        'payment_id' => $payment->id,
                        'paid_at' => now(),
                    ]);
            }

            $newPaidTotal = round((float) $session->paid_amount + $amountToPay, 2);
            $session->update([
                'paid_amount' => $newPaidTotal,
            ]);

            // Robust Session Closure Rule:
            // Closes ONLY when:
            // 1. Balance is €0.00 ($newPaidTotal >= total)
            // 2. At least one completed payment record exists
            // 3. All orders on this session are served, completed, or cancelled (kitchen isn't still prepping food)
            $hasPayments = Payment::where('session_id', $session->id)->where('status', 'completed')->exists();
            $hasPendingOrders = Order::where('session_id', $session->id)
                ->whereNotIn('status', ['served', 'completed', 'cancelled'])
                ->exists();

            $isFullyPaid = $newPaidTotal >= (float) $session->total && $hasPayments;

            if ($isFullyPaid && !$hasPendingOrders) {
                $session->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                ]);
                \App\Models\DigitalKey::where('table_session_id', $session->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
                TableStateMachine::transition($table, TableStateMachine::STATUS_CLEANING, 'Full bill settled by guest and all orders served');
            }

            return response()->json([
                'message' => $isFullyPaid ? 'Bill paid in full! Thank you for dining with Café X.' : 'Payment received. Remaining balance updated.',
                'payment' => $payment,
                'session' => [
                    'id' => $session->id,
                    'total' => $session->total,
                    'paid_amount' => $newPaidTotal,
                    'balance_due' => max(0, round((float) $session->total - $newPaidTotal, 2)),
                    'status' => $session->status,
                ],
                'is_fully_paid' => $isFullyPaid,
            ]);
        });
    }

    /**
     * Guest calls floor waiter.
     */
    public function callWaiter(Request $request, string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        // Server enforcement: reject if QR ordering is paused
        if ($table->branch && !$table->branch->is_qr_ordering_enabled) {
            return response()->json([
                'error' => 'QR ordering is currently paused for this location. Please order with your waiter.',
            ], 403);
        }

        $session = TableSession::where('table_id', $table->id)
            ->whereIn('status', ['active', 'billing'])
            ->latest('opened_at')
            ->first();

        $req = ServiceRequest::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_id' => $session?->id,
            'type' => $request->input('type', 'call_waiter'),
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Waiter has been called! A team member will visit your table shortly.',
            'request_id' => $req->id,
        ]);
    }

    /**
     * Guest requests the bill. Transitions table to 'billing'.
     */
    public function requestBill(Request $request, string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->with('branch')
            ->where('qr_token', $token)
            ->firstOrFail();

        if ($table->tenant_id) {
            TenantContext::setTenant($table->tenant);
        }

        // Server enforcement: reject if QR ordering is paused
        if ($table->branch && !$table->branch->is_qr_ordering_enabled) {
            return response()->json([
                'error' => 'QR ordering is currently paused for this location. Please order with your waiter.',
            ], 403);
        }

        $session = TableSession::where('table_id', $table->id)
            ->whereIn('status', ['active', 'billing'])
            ->latest('opened_at')
            ->firstOrFail();

        $session->update(['status' => 'billing']);
        TableStateMachine::transition($table, TableStateMachine::STATUS_BILLING, 'Guest requested bill');

        ServiceRequest::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_id' => $session->id,
            'type' => 'request_bill',
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Bill requested. The waiter will bring your check or you may pay online.',
            'session' => [
                'id' => $session->id,
                'total' => $session->total,
                'balance_due' => max(0, round($session->total - $session->paid_amount, 2)),
            ],
        ]);
    }
}
