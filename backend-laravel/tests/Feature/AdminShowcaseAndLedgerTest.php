<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShowcaseAndLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.demo_mode' => true]);
        $this->artisan('db:seed');
    }

    public function test_admin_can_retrieve_payments_and_stats(): void
    {
        $response = $this->getJson('/api/payments');
        $response->assertStatus(200);

        $statsRes = $this->getJson('/api/payments/stats');
        $statsRes->assertStatus(200)
            ->assertJsonStructure([
                'total_revenue',
                'net_revenue',
                'refunds_total',
                'transactions_count',
                'by_method',
            ]);

        $this->assertGreaterThan(0, $statsRes->json('total_revenue'));
    }

    public function test_refund_cannot_exceed_original_payment_and_prevents_over_refunding(): void
    {
        $payment = Payment::where('status', 'completed')
            ->where('amount', '>', 0)
            ->firstOrFail();

        $originalAmount = (float) $payment->amount;

        // 1. Valid partial refund (€20)
        $partialResponse = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 20.00,
            'reason' => 'Partial adjustment for late appetizer',
        ]);

        $partialResponse->assertStatus(200)
            ->assertJsonPath('message', 'Refund processed successfully.');

        // 2. Attempt over-refunding exceeding remaining balance
        $excessiveResponse = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => $originalAmount, // already refunded €20, so this exceeds
            'reason' => 'Invalid attempt to refund full amount again',
        ]);

        $excessiveResponse->assertStatus(422)
            ->assertJsonStructure(['error', 'remaining_refundable']);

        // 3. Complete remaining refund
        $remaining = $originalAmount - 20.00;
        $finalRefundRes = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => $remaining,
            'reason' => 'Remaining balance refund confirmed by manager',
        ]);
        $finalRefundRes->assertStatus(200);

        // 4. Attempt refund on fully refunded payment -> Rejected
        $exhaustedResponse = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 5.00,
            'reason' => 'Attempting refund after fully refunded',
        ]);
        $exhaustedResponse->assertStatus(422);
    }

    public function test_order_stepper_state_machine_enforces_progression_and_rejects_invalid_backwards_hops(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-STEPPER',
            'order_type' => 'dine_in',
            'status' => 'placed',
            'subtotal' => 50.00,
            'tax' => 4.00,
            'total' => 54.00,
            'guest_name' => 'Stepper Tester',
        ]);

        // Invalid: placed directly to served -> 422
        $invalidHop = $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'served',
        ]);
        $invalidHop->assertStatus(422)
            ->assertJsonPath('current_status', 'placed');

        // Valid: placed -> accepted
        $resAccepted = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'accepted']);
        $resAccepted->assertStatus(200);
        $this->assertEquals('accepted', $order->fresh()->status);

        // Valid: accepted -> preparing
        $resPrep = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'preparing']);
        $resPrep->assertStatus(200);

        // Valid: preparing -> ready
        $resReady = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'ready']);
        $resReady->assertStatus(200);

        // Invalid: ready backwards to placed -> 422
        $invalidBackHop = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'placed']);
        $invalidBackHop->assertStatus(422);

        // Valid: ready -> served
        $resServed = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'served']);
        $resServed->assertStatus(200);

        // Valid: served -> completed
        $resCompleted = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed']);
        $resCompleted->assertStatus(200);

        // Terminal state: completed cannot transition to placed -> 422
        $terminalReject = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'placed']);
        $terminalReject->assertStatus(422);
    }

    public function test_walk_in_pos_order_recomputes_prices_and_rejects_sold_out_items(): void
    {
        $table = RestaurantTable::where('table_number', 'Table 1')->firstOrFail();
        $dish = MenuItem::where('is_available', true)->firstOrFail();
        $realPrice = (float) $dish->price;

        // 1. Submit POS order with tampered unit_price (€0.01 instead of real price)
        $payload = [
            'table_id' => $table->id,
            'guest_name' => 'Counter Guest Walkin',
            'order_type' => 'dine_in',
            'notes' => 'Urgent takeout box',
            'items' => [
                [
                    'menu_item_id' => $dish->id,
                    'unit_price' => 0.01, // Client tampering attempt
                    'quantity' => 2,
                    'notes' => 'No onions',
                ],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);
        $response->assertStatus(201);

        $createdOrder = Order::where('guest_name', 'Counter Guest Walkin')->firstOrFail();
        $expectedSubtotal = round($realPrice * 2, 2);
        $expectedTax = round($expectedSubtotal * 0.08, 2);
        $expectedTotal = $expectedSubtotal + $expectedTax;

        // Server authoritative verification: client price ignored
        $this->assertEquals($expectedSubtotal, (float) $createdOrder->subtotal);
        $this->assertEquals($expectedTotal, (float) $createdOrder->total);
        $this->assertEquals($realPrice, (float) $createdOrder->items[0]->unit_price);

        // 2. Attempt ordering sold out item -> Rejected with 422
        $soldOutDish = MenuItem::create([
            'tenant_id' => $dish->tenant_id,
            'category_id' => $dish->category_id,
            'name' => 'Special Truffle Caviar (Sold Out)',
            'price' => 99.00,
            'is_available' => false,
        ]);

        $soldOutOrderPayload = [
            'table_id' => $table->id,
            'guest_name' => 'Sold Out Requester',
            'items' => [
                ['menu_item_id' => $soldOutDish->id, 'quantity' => 1],
            ],
        ];

        $soldOutRes = $this->postJson('/api/orders', $soldOutOrderPayload);
        $soldOutRes->assertStatus(422)
            ->assertJsonPath('menu_item_id', $soldOutDish->id);
    }

    public function test_dashboard_revenue_and_payment_ledger_reconcile_identically(): void
    {
        $dashRes = $this->getJson('/api/dashboard/stats');
        $dashRes->assertStatus(200);

        $paymentStatsRes = $this->getJson('/api/payments/stats');
        $paymentStatsRes->assertStatus(200);

        $dashNetRevenue = (float) $dashRes->json('stats.net_revenue');
        $ledgerNetRevenue = (float) $paymentStatsRes->json('net_revenue');

        // Mathematical parity between Dashboard KPI and Payment Ledger
        $this->assertEquals($ledgerNetRevenue, $dashNetRevenue);
    }

    public function test_audit_trail_endpoint_returns_events(): void
    {
        $response = $this->getJson('/api/audit-logs');
        $response->assertStatus(200);

        $logs = $response->json();
        $this->assertIsArray($logs);
        $this->assertGreaterThan(0, count($logs));
    }

    public function test_demo_reset_requires_demo_mode_and_blocks_in_production(): void
    {
        // 1. Normal demo reset when DEMO_MODE=true
        $response = $this->postJson('/api/demo/reset');
        $response->assertStatus(200)
            ->assertJsonPath('message', 'Demo data reset and seeded successfully.');

        // 2. Blocked if DEMO_MODE=false
        config(['app.demo_mode' => false]);
        $blockedRes = $this->postJson('/api/demo/reset');
        $blockedRes->assertStatus(403);
    }
}
