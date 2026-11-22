<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TableStateMachine;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrDiningSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed database
        $this->artisan('db:seed');
    }

    public function test_qr_scan_is_strictly_read_only_and_creates_no_phantom_session(): void
    {
        // Table 1 is available
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $this->assertEquals('available', $table->status);

        $initialSessionCount = TableSession::where('table_id', $table->id)->count();

        // Perform GET request (as a link crawler or preview bot would)
        $response = $this->getJson("/api/t/8f3k2x");

        $response->assertStatus(200)
            ->assertJsonPath('table.table_number', 'Table 1')
            ->assertJsonPath('has_active_session', false);

        // Verify NO session was created and table is still available
        $this->assertEquals($initialSessionCount, TableSession::where('table_id', $table->id)->count());
        $this->assertEquals('available', $table->fresh()->status);
    }

    public function test_qr_join_creates_session_and_issues_cryptographic_guest_secret(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();

        $response = $this->postJson("/api/t/8f3k2x/join", [
            'guest_name' => 'Abel',
            'guest_phone' => '+15550100003',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'guest' => ['id', 'guest_name'],
                'guest_secret',
                'session_token',
            ]);

        $guestSecret = $response->json('guest_secret');
        $this->assertStringStartsWith('gs_', $guestSecret);
        $this->assertGreaterThan(30, strlen($guestSecret));

        // Table transitions to occupied
        $this->assertEquals('occupied', $table->fresh()->status);
    }

    public function test_missing_or_invalid_guest_secret_is_rejected(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();

        // 1. Join to start session
        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Valid Diner']);
        $validSecret = $joinRes->json('guest_secret');

        // 2. Attempt to order with forged / wrong guest secret -> Rejected with 403
        $forgedRes = $this->withHeaders(['X-Guest-Secret' => 'gs_forged_fake_secret_123456789'])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Hacker',
                'items' => [
                    ['menu_item_id' => $pizza->id, 'quantity' => 1],
                ],
            ]);
        $forgedRes->assertStatus(403)
            ->assertJsonPath('error', 'Unauthorized dining session. Valid guest secret required.');

        // 3. Attempt with valid secret -> Accepted with 201
        $legitRes = $this->withHeaders(['X-Guest-Secret' => $validSecret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Valid Diner',
                'items' => [
                    ['menu_item_id' => $pizza->id, 'quantity' => 1],
                ],
            ]);
        $legitRes->assertStatus(201);
    }

    public function test_server_recalculates_price_ignoring_client_tampering(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $wagyu = MenuItem::where('name', 'Wagyu Ribeye Steak')->firstOrFail();

        // Join session first
        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Attacker']);
        $secret = $joinRes->json('guest_secret');

        // Tamper attempt: client claims price is 0.01 instead of 85.00
        $response = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Attacker',
                'items' => [
                    [
                        'menu_item_id' => $wagyu->id,
                        'unit_price' => 0.01, // Client trying to cheat price
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $order = $response->json('order');

        // Verify server computed subtotal based on real DB menu price, NOT client 0.01
        $this->assertEquals(85.00, (float) $order['subtotal']);
        $this->assertGreaterThan(85.00, (float) $order['total']);
    }

    public function test_order_idempotency_prevents_duplicate_orders_and_rejects_conflicting_payload(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();
        $wagyu = MenuItem::where('name', 'Wagyu Ribeye Steak')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Sara']);
        $secret = $joinRes->json('guest_secret');

        $idempotencyKey = 'idempotent-test-key-' . uniqid();

        $payload = [
            'guest_name' => 'Sara',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                [
                    'menu_item_id' => $pizza->id,
                    'quantity' => 1,
                ],
            ],
        ];

        // Send first request
        $res1 = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", $payload);
        $res1->assertStatus(201);
        $orderId1 = $res1->json('order.id');

        // Send second request with IDENTICAL payload -> Replays same order
        $res2 = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", $payload);
        $res2->assertStatus(200);
        $orderId2 = $res2->json('order.id');
        $this->assertEquals($orderId1, $orderId2);

        // Send third request with SAME key but DIFFERENT payload -> 422 Conflict
        $conflictingPayload = [
            'guest_name' => 'Sara',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                [
                    'menu_item_id' => $wagyu->id, // Changed to Wagyu!
                    'quantity' => 3,
                ],
            ],
        ];
        $resConflict = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", $conflictingPayload);
        $resConflict->assertStatus(422)
            ->assertJsonPath('error', 'Idempotency conflict: key was previously used with a different request payload.');
    }

    public function test_atomic_cancellation_race_rejected_after_kitchen_accepts(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $tiramisu = MenuItem::where('name', 'Traditional Tiramisu')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Marco']);
        $secret = $joinRes->json('guest_secret');

        $orderRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Marco',
                'items' => [
                    ['menu_item_id' => $tiramisu->id, 'quantity' => 1],
                ],
            ]);
        $orderId = $orderRes->json('order.id');
        $order = Order::findOrFail($orderId);

        // Simulate kitchen accepts right before customer cancel hits
        $order->update(['status' => 'accepted', 'accepted_at' => now()]);

        // Diner attempts to cancel
        $cancelRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/orders/{$order->id}/cancel");
        $cancelRes->assertStatus(422)
            ->assertJsonPath('error', "Cannot cancel order. The kitchen is already accepted your dishes.");

        $this->assertEquals('accepted', $order->fresh()->status);
    }

    public function test_token_regeneration_invalidates_old_and_issues_128bit_token(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $oldToken = $table->qr_token;

        // Admin regenerates token
        $regenRes = $this->postJson("/api/tables/{$table->id}/regenerate-qr");
        $regenRes->assertStatus(200);
        $newToken = $regenRes->json('qr_token');

        // Must be 128-bit (32 hex characters)
        $this->assertEquals(32, strlen($newToken));
        $this->assertNotEquals($oldToken, $newToken);

        // Old token immediately fails with 404
        $this->getJson("/api/t/{$oldToken}")->assertStatus(404);

        // New token resolves correctly
        $this->getJson("/api/t/{$newToken}")->assertStatus(200)
            ->assertJsonPath('table.table_number', 'Table 1');
    }

    public function test_server_rejects_orders_when_qr_ordering_is_paused(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $branch = $table->branch;

        // Emergency pause
        $branch->update(['is_qr_ordering_enabled' => false]);

        // 1. Scan returns 403
        $this->getJson("/api/t/8f3k2x")->assertStatus(403);

        // 2. Join returns 403
        $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Eve'])->assertStatus(403);

        // 3. Order returns 403
        $this->postJson("/api/t/8f3k2x/order", [
            'guest_name' => 'Eve',
            'items' => [['menu_item_id' => 1, 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_quantity_tampering_bounds_are_enforced(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Bob']);
        $secret = $joinRes->json('guest_secret');

        // Negative quantity -> Rejected
        $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Bob',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => -5]],
            ])->assertStatus(422);

        // Zero quantity -> Rejected
        $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Bob',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => 0]],
            ])->assertStatus(422);

        // Absurdly high quantity (> 50) -> Rejected
        $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Bob',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => 999]],
            ])->assertStatus(422);
    }

    public function test_state_machine_transitions_billing_to_occupied_when_ordering_again(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();
        $tiramisu = MenuItem::where('name', 'Traditional Tiramisu')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Diner']);
        $secret = $joinRes->json('guest_secret');

        // First order
        $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Diner',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => 1]],
            ]);

        // Request bill -> Table and session move to 'billing'
        $this->postJson("/api/t/8f3k2x/request-bill");
        $this->assertEquals('billing', $table->fresh()->status);

        // Guest decides to order dessert!
        $secondOrderRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Diner',
                'items' => [['menu_item_id' => $tiramisu->id, 'quantity' => 1]],
            ]);
        $secondOrderRes->assertStatus(201);

        // Table transitions back to occupied, session back to active
        $this->assertEquals('occupied', $table->fresh()->status);
        $session = TableSession::where('table_id', $table->id)->latest('opened_at')->first();
        $this->assertEquals('active', $session->status);
    }

    public function test_customer_payment_creates_payment_record_and_closes_session(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Payer']);
        $secret = $joinRes->json('guest_secret');

        $orderRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Payer',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => 1]],
            ]);
        $orderTotal = (float) $orderRes->json('order.total');
        $orderId = (int) $orderRes->json('order.id');

        // Mark order as served before settling bill
        Order::where('id', $orderId)->update(['status' => 'served']);

        // Full Pay Now
        $payRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/pay", [
                'amount' => $orderTotal,
                'payment_method' => 'card',
                'split_type' => 'full',
            ]);

        $payRes->assertStatus(200)
            ->assertJsonPath('is_fully_paid', true);

        // Payment record created in DB
        $this->assertEquals(1, Payment::where('amount', $orderTotal)->count());

        // Session closed and table moved to cleaning
        $this->assertEquals('cleaning', $table->fresh()->status);
    }

    public function test_manager_override_requires_proper_note_length(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();
        $pizza = MenuItem::where('name', 'Margherita Classica')->firstOrFail();

        $joinRes = $this->postJson("/api/t/8f3k2x/join", ['guest_name' => 'Guest']);
        $secret = $joinRes->json('guest_secret');

        $orderRes = $this->withHeaders(['X-Guest-Secret' => $secret])
            ->postJson("/api/t/8f3k2x/order", [
                'guest_name' => 'Guest',
                'items' => [['menu_item_id' => $pizza->id, 'quantity' => 1]],
            ]);
        $session = TableSession::where('table_id', $table->id)->latest('opened_at')->first();

        // 1. Unpaid balance without override -> 422
        $failRes = $this->postJson("/api/waiter/sessions/{$session->id}/settle", [
            'paid_amount' => 5.00, // Partial payment
            'manager_override' => false,
        ]);
        $failRes->assertStatus(422);

        // 2. Override with note too short (< 10 chars) -> 422 validation error
        $shortNoteRes = $this->postJson("/api/waiter/sessions/{$session->id}/settle", [
            'paid_amount' => 5.00,
            'manager_override' => true,
            'notes' => 'short',
        ]);
        $shortNoteRes->assertStatus(422);

        // 3. Override with valid detailed note -> 200 and audit log recorded
        $okRes = $this->postJson("/api/waiter/sessions/{$session->id}/settle", [
            'paid_amount' => 5.00,
            'manager_override' => true,
            'notes' => 'Customer complained about cold drink, 50% discount authorized by manager Luca',
        ]);
        $okRes->assertStatus(200);
        $this->assertEquals('cleaning', $table->fresh()->status);
        $this->assertEquals(1, AuditLog::where('action', 'session_override_settle')->count());
    }

    public function test_multi_tenant_isolation_prevents_cross_tenant_access(): void
    {
        // Create Tenant B
        $tenantB = Tenant::create([
            'name' => 'Rival Restaurant Corp',
            'slug' => 'rival',
        ]);

        $branchB = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Rival Paris',
            'slug' => 'paris',
        ]);

        $tableB = RestaurantTable::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'table_number' => 'Table B1',
            'qr_token' => 'rival_token_xyz',
            'status' => 'available',
        ]);

        // Attempting to order with Tenant A's menu item on Tenant B's table must fail
        $wagyuTenantA = MenuItem::where('name', 'Wagyu Ribeye Steak')->firstOrFail();

        $joinRes = $this->postJson("/api/t/{$tableB->qr_token}/join", ['guest_name' => 'Spy']);
        $spySecret = $joinRes->json('guest_secret');

        $crossOrderRes = $this->withHeaders(['X-Guest-Secret' => $spySecret])
            ->postJson("/api/t/{$tableB->qr_token}/order", [
                'guest_name' => 'Spy',
                'items' => [
                    ['menu_item_id' => $wagyuTenantA->id, 'quantity' => 1],
                ],
            ]);

        // Rejected because menu item does not belong to Tenant B
        $crossOrderRes->assertStatus(422);
    }

    public function test_concurrent_join_race_recovers_gracefully_from_unique_index_collision(): void
    {
        $table = RestaurantTable::where('qr_token', '8f3k2x')->firstOrFail();

        // Simulate diner 1 joining
        $res1 = $this->postJson("/api/t/{$table->qr_token}/join", ['guest_name' => 'Diner 1']);
        $res1->assertStatus(200);
        $sessionToken1 = $res1->json('session_token');

        // Simulate concurrent diner 2 joining
        $res2 = $this->postJson("/api/t/{$table->qr_token}/join", ['guest_name' => 'Diner 2']);
        $res2->assertStatus(200);
        $sessionToken2 = $res2->json('session_token');

        // Both diners must share the exact same session token without crashing with 500
        $this->assertEquals($sessionToken1, $sessionToken2);
        $this->assertNotEquals($res1->json('guest_secret'), $res2->json('guest_secret'));
    }
}
