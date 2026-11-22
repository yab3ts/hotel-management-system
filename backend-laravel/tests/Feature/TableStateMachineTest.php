<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use App\Services\TableStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    public function test_valid_table_lifecycle_transitions(): void
    {
        $table = RestaurantTable::where('table_number', 'Table 1')->firstOrFail();
        $this->assertEquals(TableStateMachine::STATUS_AVAILABLE, $table->status);

        // 1. Available -> Occupied
        TableStateMachine::transition($table, TableStateMachine::STATUS_OCCUPIED);
        $this->assertEquals(TableStateMachine::STATUS_OCCUPIED, $table->fresh()->status);

        // 2. Occupied -> Billing
        TableStateMachine::transition($table, TableStateMachine::STATUS_BILLING);
        $this->assertEquals(TableStateMachine::STATUS_BILLING, $table->fresh()->status);

        // 3. Billing -> Cleaning
        TableStateMachine::transition($table, TableStateMachine::STATUS_CLEANING);
        $this->assertEquals(TableStateMachine::STATUS_CLEANING, $table->fresh()->status);

        // 4. Cleaning -> Available
        TableStateMachine::transition($table, TableStateMachine::STATUS_AVAILABLE);
        $this->assertEquals(TableStateMachine::STATUS_AVAILABLE, $table->fresh()->status);
    }

    public function test_session_closing_rule_requires_payment_and_all_orders_served(): void
    {
        $table = RestaurantTable::where('table_number', 'Table 1')->firstOrFail();
        TableStateMachine::transition($table, TableStateMachine::STATUS_OCCUPIED);

        $session = \App\Models\TableSession::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
            'status' => 'active',
            'subtotal' => 20.00,
            'tax' => 2.00,
            'service_charge' => 1.00,
            'total' => 23.00,
            'paid_amount' => 0.00,
            'opened_at' => now(),
        ]);

        $guest = \App\Models\SessionGuest::create([
            'session_id' => $session->id,
            'guest_name' => 'Abel Test',
            'guest_secret' => 'sec_' . bin2hex(random_bytes(16)),
        ]);

        $order = \App\Models\Order::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_id' => $session->id,
            'guest_id' => $guest->id,
            'guest_name' => 'Abel Test',
            'order_number' => 'ORD-TEST-1',
            'subtotal' => 20.00,
            'tax' => 2.00,
            'service_charge' => 1.00,
            'total' => 23.00,
            'status' => 'preparing', // Kitchen still preparing food!
            'payment_choice' => 'pay_now',
        ]);

        // Customer pays full €23.00 via API
        $payRes = $this->withHeader('X-Guest-Secret', $guest->guest_secret)
            ->postJson("/api/t/{$table->qr_token}/pay", [
                'amount' => 23.00,
                'payment_method' => 'card',
                'split_type' => 'full',
            ]);
        $payRes->assertStatus(200);

        // Verification 1: Session total is paid, but order is still PREPARING in kitchen!
        // Session must NOT close yet, and table must NOT be in cleaning!
        $this->assertEquals(23.00, (float) $session->fresh()->paid_amount);
        $this->assertEquals('active', $session->fresh()->status);
        $this->assertNotEquals(TableStateMachine::STATUS_CLEANING, $table->fresh()->status);

        // Verification 2: Waiter marks order as served
        $admin = \App\Models\User::where('email', 'admin@cafex.com')->first();
        $orderRes = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", [
                'status' => 'ready',
            ]);
        $orderRes->assertStatus(200);

        $orderRes2 = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/status", [
                'status' => 'served',
            ]);
        $orderRes2->assertStatus(200);

        // Now that order is SERVED and bill is fully paid:
        // Session atomically closes and table transitions to cleaning!
        $this->assertEquals('closed', $session->fresh()->status);
        $this->assertEquals(TableStateMachine::STATUS_CLEANING, $table->fresh()->status);
    }

    public function test_invalid_table_transition_is_strictly_rejected(): void
    {
        $table = RestaurantTable::where('table_number', 'Table 1')->firstOrFail();
        $this->assertEquals(TableStateMachine::STATUS_AVAILABLE, $table->status);

        // An available table cannot jump directly to cleaning without having been occupied/billed
        $this->expectException(ValidationException::class);
        TableStateMachine::transition($table, TableStateMachine::STATUS_CLEANING);
    }

    public function test_unique_partial_index_prevents_duplicate_open_sessions_per_table(): void
    {
        $table = RestaurantTable::where('table_number', 'Table 1')->firstOrFail();

        // 1. First active session succeeds
        $session1 = \App\Models\TableSession::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_token' => 'sess_active_1',
            'status' => 'active',
            'opened_at' => now(),
        ]);
        $this->assertNotNull($session1->id);

        // 2. Second concurrent open session for SAME table must trigger unique partial index violation
        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\TableSession::create([
            'tenant_id' => $table->tenant_id,
            'branch_id' => $table->branch_id,
            'table_id' => $table->id,
            'session_token' => 'sess_active_2_race',
            'status' => 'active',
            'opened_at' => now(),
        ]);
    }
}
