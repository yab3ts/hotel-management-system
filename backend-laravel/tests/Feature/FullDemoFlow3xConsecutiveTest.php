<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantTable;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullDemoFlow3xConsecutiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_9_step_demo_flow_executes_three_times_consecutively_without_database_resets(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Setup initial tenant, branch, and menu dishes
        $tenant = Tenant::create(['name' => 'Café X 3x Demo', 'slug' => 'cafex-3x-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Milan Flagship',
            'slug' => 'milan-' . uniqid(),
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
            'is_qr_ordering_enabled' => true,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Signature',
            'slug' => 'sig-' . uniqid(),
        ]);

        $dish1 = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Wagyu Ribeye Steak',
            'price' => 80.00,
            'is_available' => true,
        ]);

        $dish2 = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Tiramisu Classico',
            'price' => 20.00,
            'is_available' => true,
        ]);

        // Run the complete 9-step flow 3 times consecutively on distinct tables
        for ($run = 1; $run <= 3; $run++) {
            $tableNumber = "Table " . $run;
            $token = "qr_run_{$run}_" . bin2hex(random_bytes(6));

            $table = RestaurantTable::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'table_number' => $tableNumber,
                'capacity' => 4,
                'zone' => 'Main',
                'status' => 'available',
                'qr_token' => $token,
            ]);

            // STEP 1: Scan table QR (Read-only, no phantom session)
            $resScan = $this->getJson("/api/t/{$token}");
            $resScan->assertStatus(200);
            $this->assertEquals($tableNumber, $resScan->json('table.table_number'));
            $this->assertFalse($resScan->json('has_active_session'));

            // STEP 2: Co-Diner 1 (Abel) and Co-Diner 2 (Beth) join the table session
            $resJoin1 = $this->postJson("/api/t/{$token}/join", ['guest_name' => 'Abel']);
            $resJoin1->assertStatus(200);
            $secret1 = $resJoin1->json('guest_secret');
            $sessionToken = $resJoin1->json('session_token');
            $this->assertNotEmpty($secret1);

            $resJoin2 = $this->postJson("/api/t/{$token}/join", ['guest_name' => 'Beth']);
            $resJoin2->assertStatus(200);
            $secret2 = $resJoin2->json('guest_secret');
            $this->assertNotEmpty($secret2);
            $this->assertEquals($sessionToken, $resJoin2->json('session_token')); // Joined same session

            // Table status is now occupied
            $this->assertEquals('occupied', $table->fresh()->status);

            // STEP 3: Both guests place orders with notes (Zero-trust server pricing)
            $resOrder1 = $this->withHeader('X-Guest-Secret', $secret1)->postJson("/api/t/{$token}/order", [
                'guest_name' => 'Abel',
                'items' => [
                    ['menu_item_id' => $dish1->id, 'quantity' => 1, 'special_notes' => 'Medium rare'],
                ],
                'idempotency_key' => "run_{$run}_order_abel_1",
            ]);
            $resOrder1->assertStatus(201);
            $order1Id = $resOrder1->json('order.id');
            $order1Item = $resOrder1->json('order.items.0.id');

            $resOrder2 = $this->withHeader('X-Guest-Secret', $secret2)->postJson("/api/t/{$token}/order", [
                'guest_name' => 'Beth',
                'items' => [
                    ['menu_item_id' => $dish2->id, 'quantity' => 1, 'special_notes' => 'Extra cocoa'],
                ],
                'idempotency_key' => "run_{$run}_order_beth_2",
            ]);
            $resOrder2->assertStatus(201);
            $order2Id = $resOrder2->json('order.id');
            $order2Item = $resOrder2->json('order.items.0.id');

            // Session total is recomputed: ($80 + $20) = $100 subtotal + 10% tax ($10) + 5% service ($5) = $115.00
            $resTab = $this->getJson("/api/t/{$token}/tab");
            $resTab->assertStatus(200);
            $this->assertEquals(115.00, (float) $resTab->json('session.total'));

            // STEP 4: Kitchen Bump Bar progresses orders: placed -> accepted -> preparing -> ready
            // Order 1 progression
            $this->patchJson("/api/orders/{$order1Id}/status", ['status' => 'accepted'])->assertStatus(200);
            $this->patchJson("/api/orders/{$order1Id}/status", ['status' => 'preparing'])->assertStatus(200);
            $this->patchJson("/api/orders/{$order1Id}/status", ['status' => 'ready'])->assertStatus(200);

            // Order 2 progression
            $this->patchJson("/api/orders/{$order2Id}/status", ['status' => 'accepted'])->assertStatus(200);
            $this->patchJson("/api/orders/{$order2Id}/status", ['status' => 'preparing'])->assertStatus(200);
            $this->patchJson("/api/orders/{$order2Id}/status", ['status' => 'ready'])->assertStatus(200);

            // Customer tracker sees 'ready'
            $resTabReady = $this->getJson("/api/t/{$token}/tab");
            $this->assertEquals('ready', $resTabReady->json('orders.0.status'));

            // STEP 5: Waiter serves orders
            $this->patchJson("/api/waiter/orders/{$order1Id}/serve")->assertStatus(200);
            $this->patchJson("/api/waiter/orders/{$order2Id}/serve")->assertStatus(200);

            // STEP 6: Guest calls waiter & requests bill
            $resCall = $this->withHeader('X-Guest-Secret', $secret1)->postJson("/api/t/{$token}/call-waiter");
            $resCall->assertStatus(200);

            $resReqBill = $this->withHeader('X-Guest-Secret', $secret1)->postJson("/api/t/{$token}/request-bill");
            $resReqBill->assertStatus(200);

            $this->assertEquals('billing', $table->fresh()->status);

            // STEP 7: Guests split the bill by item and pay
            // Abel pays for Steak ($80 + $8 tax + $4 svc = $92.00)
            $resPayAbel = $this->withHeader('X-Guest-Secret', $secret1)->postJson("/api/t/{$token}/pay", [
                'payment_method' => 'card',
                'split_type' => 'item',
                'selected_item_ids' => [$order1Item],
            ]);
            $resPayAbel->assertStatus(200);
            $this->assertEquals(92.00, (float) $resPayAbel->json('payment.amount'));

            // Beth pays remaining for Tiramisu ($20 + $2 tax + $1 svc = $23.00)
            $resPayBeth = $this->withHeader('X-Guest-Secret', $secret2)->postJson("/api/t/{$token}/pay", [
                'payment_method' => 'card',
                'split_type' => 'item',
                'selected_item_ids' => [$order2Item],
            ]);
            $resPayBeth->assertStatus(200);
            $this->assertEquals(23.00, (float) $resPayBeth->json('payment.amount'));

            // STEP 8: Session is now fully paid (paid_amount == 115.00), table turnover: cleaning -> free
            $sessionId = $resTab->json('session.id');
            $this->postJson("/api/waiter/sessions/{$sessionId}/settle", ['notes' => 'Settled in full'])->assertStatus(200);

            $this->assertEquals('cleaning', $table->fresh()->status);
            $this->patchJson("/api/waiter/tables/{$table->id}/clean")->assertStatus(200);
            $this->assertEquals('available', $table->fresh()->status);

            // STEP 9: Admin sees revenue, orders, and audit trail
            $resStats = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/dashboard/stats');
            $resStats->assertStatus(200);
            $this->assertGreaterThanOrEqual(115.00 * $run, (float) $resStats->json('stats.net_revenue'));

            $resAudit = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/audit-logs');
            $resAudit->assertStatus(200);
            $this->assertNotEmpty($resAudit->json());
        }
    }
}
