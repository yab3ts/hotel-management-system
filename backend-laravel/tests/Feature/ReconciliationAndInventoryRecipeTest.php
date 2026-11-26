<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationAndInventoryRecipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_reconciliation_calculates_exact_revenue_and_balances(): void
    {
        $tenant = Tenant::create(['name' => 'Café Recon', 'slug' => 'recon-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'slug' => 'main-' . uniqid(),
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
        ]);
        $table = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'T-REC',
            'status' => 'occupied',
            'capacity' => 4,
            'qr_token' => 'recon_' . bin2hex(random_bytes(8)),
        ]);
        $session = TableSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_id' => $table->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
            'status' => 'closed',
            'total' => 100.00,
            'paid_amount' => 100.00,
            'opened_at' => now(),
            'closed_at' => now(),
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'table_id' => $table->id,
            'guest_name' => 'Recon Diner',
            'order_number' => '#REC-101',
            'status' => 'completed',
            'subtotal' => 86.96,
            'tax' => 8.70,
            'service_charge' => 4.34,
            'total' => 100.00,
            'payment_status' => 'completed',
            'placed_at' => now(),
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'amount' => 100.00,
            'currency' => 'EUR',
            'payment_method' => 'card',
            'status' => 'completed',
            'transaction_id' => 'tx_rec_100',
        ]);

        $admin = \App\Models\User::create([
            'name' => 'Admin Test',
            'email' => 'admin_recon_' . uniqid() . '@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($admin->id, ['role' => 'admin']);

        // 1. Unauthenticated request MUST be rejected with 401
        $unauthRes = $this->getJson('/api/reports/reconciliation?date=' . now()->toDateString());
        $unauthRes->assertStatus(401);

        // 2. Authenticated administrator request succeeds
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/reconciliation?date=' . now()->toDateString());
        $response->assertStatus(200);

        $data = $response->json('summary');
        $this->assertEquals(100.00, $data['orders_gross_total']);
        $this->assertEquals(100.00, $data['net_collected']);
        $this->assertEquals(0.00, $data['discrepancy']);
        $this->assertTrue($data['is_balanced']);
    }

    public function test_tax_compliant_session_receipt_generation(): void
    {
        $tenant = Tenant::create(['name' => 'Café Fiscal', 'slug' => 'fiscal-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal Branch',
            'slug' => 'fiscal-' . uniqid(),
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
        ]);
        $table = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'T-FISC',
            'status' => 'occupied',
            'capacity' => 2,
            'qr_token' => 'fisc_' . bin2hex(random_bytes(8)),
        ]);
        $session = TableSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_id' => $table->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
            'status' => 'active',
            'subtotal' => 50.00,
            'tax' => 5.00,
            'service_charge' => 2.50,
            'total' => 57.50,
            'paid_amount' => 57.50,
            'opened_at' => now(),
        ]);

        // Unauthorized IDOR attempt without guest secret or staff auth MUST fail (#19)
        $this->getJson("/api/sessions/{$session->id}/receipt")->assertStatus(403);

        // Query-string token MUST NOT be accepted (#72/#79)
        $this->getJson("/api/sessions/{$session->id}/receipt?token={$session->session_token}")->assertStatus(403);

        // Authorized via X-Session-Token header
        $response = $this->withHeader('X-Session-Token', $session->session_token)
            ->getJson("/api/sessions/{$session->id}/receipt");
        $response->assertStatus(200);
        $receipt = $response->json('receipt');

        $this->assertStringStartsWith('RCP-', $receipt['receipt_number']);
        $this->assertEquals(57.50, $receipt['financials']['total']);
        $this->assertEquals('EUR', $receipt['financials']['currency']);
        $this->assertNotEmpty($receipt['business']['tax_id']);
    }

    public function test_inventory_listing_and_recipe_auto_deduction_and_cancellation(): void
    {
        $tenant = Tenant::create(['name' => 'Café Inv', 'slug' => 'inv-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inv Branch',
            'slug' => 'inv-b-' . uniqid(),
            'currency' => 'EUR',
            'is_qr_ordering_enabled' => true,
        ]);
        $table = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'T-INV',
            'status' => 'occupied',
            'capacity' => 4,
            'qr_token' => 'inv_' . bin2hex(random_bytes(8)),
        ]);
        $session = TableSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_id' => $table->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
            'status' => 'active',
            'opened_at' => now(),
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
            'slug' => 'pizzas-' . uniqid(),
        ]);
        $pizza = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Margherita Pizza',
            'price' => 15.00,
            'is_available' => true,
        ]);

        // Multi-ingredient recipe ingredients
        $mozzarella = InventoryItem::create([
            'tenant_id' => $tenant->id,
            'item_name' => 'Buffalo Mozzarella DOP',
            'current_stock' => 10.00,
            'minimum_stock' => 2.00,
            'unit' => 'kg',
            'cost_per_unit' => 12.00,
        ]);
        $flour = InventoryItem::create([
            'tenant_id' => $tenant->id,
            'item_name' => 'All-Purpose Italian Flour 00',
            'current_stock' => 25.00,
            'minimum_stock' => 5.00,
            'unit' => 'kg',
            'cost_per_unit' => 2.00,
        ]);
        $basil = InventoryItem::create([
            'tenant_id' => $tenant->id,
            'item_name' => 'Fresh Organic Basil',
            'current_stock' => 1.00,
            'minimum_stock' => 0.10,
            'unit' => 'kg',
            'cost_per_unit' => 15.00,
        ]);

        $chef = User::create([
            'name' => 'Chef Marco',
            'email' => 'chef_' . uniqid() . '@cafex.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($chef->id, ['role' => 'kitchen']);

        $resList = $this->actingAs($chef, 'sanctum')->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/inventory');
        $resList->assertStatus(200);
        $this->assertCount(3, $resList->json('items'));

        // Join session to obtain authorized guest secret
        $joinRes = $this->postJson("/api/t/{$table->qr_token}/join", [
            'guest_name' => 'Marco Diner',
        ]);
        $joinRes->assertStatus(200);
        $guestSecret = $joinRes->json('guest_secret');

        // Place QR order for 2 Margherita Pizzas
        $orderRes = $this->withHeader('X-Guest-Secret', $guestSecret)->postJson("/api/t/{$table->qr_token}/order", [
            'guest_name' => 'Marco Diner',
            'items' => [
                [
                    'menu_item_id' => $pizza->id,
                    'quantity' => 2,
                    'special_notes' => 'Crispy crust',
                ],
            ],
        ]);
        $orderRes->assertStatus(201);
        $orderId = $orderRes->json('order.id');

        // Check multi-ingredient auto-deduction:
        // Mozzarella: 10.00 - (0.15 * 2 = 0.30) = 9.70
        // Flour: 25.00 - (0.20 * 2 = 0.40) = 24.60
        // Basil: 1.00 - (0.02 * 2 = 0.04) = 0.96
        $this->assertEquals(9.70, (float) $mozzarella->fresh()->current_stock);
        $this->assertEquals(24.60, (float) $flour->fresh()->current_stock);
        $this->assertEquals(0.96, (float) $basil->fresh()->current_stock);

        // Cancel order: should restore all ingredients back to original stock
        $cancelRes = $this->withHeader('X-Guest-Secret', $guestSecret)->postJson("/api/t/{$table->qr_token}/orders/{$orderId}/cancel", [
            'reason' => 'Accidental order',
        ]);
        $cancelRes->assertStatus(200);

        $this->assertEquals(10.00, (float) $mozzarella->fresh()->current_stock);
        $this->assertEquals(25.00, (float) $flour->fresh()->current_stock);
        $this->assertEquals(1.00, (float) $basil->fresh()->current_stock);
    }
}
