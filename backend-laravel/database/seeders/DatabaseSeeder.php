<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\AuditLog;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with multi-tenant and multi-branch data.
     */
    public function run(): void
    {
        // Guard: Prevent running demo seeder in production unless DEMO_MODE is explicitly enabled
        if (app()->isProduction() && !config('app.demo_mode')) {
            throw new \RuntimeException("Seeding demo accounts and test data is strictly forbidden in production environments unless DEMO_MODE=true is set.");
        }

        // 1. Create Tenant (Restaurant Brand)
        $tenant = Tenant::create([
            'name' => 'Café X Enterprise Group',
            'slug' => 'cafex',
            'logo_url' => '/logo.svg',
            'is_active' => true,
        ]);

        // 2. Create Multiple Branches for this Tenant
        $milanBranch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Café X Milan (Downtown)',
            'code' => 'MX-01',
            'slug' => 'milan',
            'phone' => '+39 02 1234567',
            'address' => 'Via Monte Napoleone 12, Milan, Italy',
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
            'is_qr_ordering_enabled' => true,
        ]);

        $romeBranch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Café X Grand Roma',
            'code' => 'RO-02',
            'slug' => 'rome',
            'phone' => '+39 06 9876543',
            'address' => 'Piazza Navona 45, Rome, Italy',
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
            'is_qr_ordering_enabled' => true,
        ]);

        // 3. Create Users
        $admin = User::create([
            'name' => 'Café Administrator',
            'email' => 'admin@cafex.com',
            'password' => Hash::make('admin123'),
            'phone' => '+1 (555) 010-0001',
            'current_tenant_id' => $tenant->id,
        ]);

        $waiter = User::create([
            'name' => 'Luca Waiter',
            'email' => 'waiter@cafex.com',
            'password' => Hash::make('waiter123'),
            'phone' => '+1 (555) 010-0004',
            'current_tenant_id' => $tenant->id,
        ]);

        $kitchen = User::create([
            'name' => 'Head Chef Marco',
            'email' => 'kitchen@cafex.com',
            'password' => Hash::make('kitchen123'),
            'phone' => '+1 (555) 010-0002',
            'current_tenant_id' => $tenant->id,
        ]);

        $customer = User::create([
            'name' => 'Abel Customer',
            'email' => 'customer@cafex.com',
            'password' => Hash::make('customer123'),
            'phone' => '+1 (555) 010-0003',
            'current_tenant_id' => $tenant->id,
        ]);

        // Attach tenant roles
        $tenant->users()->attach($admin->id, ['role' => 'admin']);
        $tenant->users()->attach($waiter->id, ['role' => 'waiter']);
        $tenant->users()->attach($kitchen->id, ['role' => 'kitchen_staff']);
        $tenant->users()->attach($customer->id, ['role' => 'customer']);

        // 4. Seed Branch Tables with QR Tokens & Menus
        $this->seedBranch($tenant, $milanBranch);
        $this->seedBranch($tenant, $romeBranch);

        // 5. Seed sample completed payments
        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $milanBranch->id,
            'amount' => 65.00,
            'currency' => 'EUR',
            'payment_method' => 'card',
            'payment_type' => 'full',
            'status' => 'completed',
            'transaction_reference' => 'TXN-9021',
            'notes' => 'Table 3 settlement by guest',
            'created_at' => now()->subHours(2),
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $milanBranch->id,
            'amount' => 42.50,
            'currency' => 'EUR',
            'payment_method' => 'mobile_money',
            'payment_type' => 'split_equal',
            'status' => 'completed',
            'transaction_reference' => 'TXN-9022',
            'notes' => 'Table 5 - Equal split 1 of 2',
            'created_at' => now()->subHours(1),
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $milanBranch->id,
            'amount' => 42.50,
            'currency' => 'EUR',
            'payment_method' => 'cash',
            'payment_type' => 'split_equal',
            'status' => 'completed',
            'transaction_reference' => 'TXN-9023',
            'notes' => 'Table 5 - Equal split 2 of 2 (cash counter)',
            'created_at' => now()->subMinutes(50),
        ]);

        // 5b. Seed 7 days of historical transactions and orders for realistic telemetry
        $historicalData = [
            ['days_ago' => 6, 'amount' => 145.00, 'method' => 'card'],
            ['days_ago' => 5, 'amount' => 210.50, 'method' => 'mobile_money'],
            ['days_ago' => 4, 'amount' => 180.00, 'method' => 'card'],
            ['days_ago' => 3, 'amount' => 320.00, 'method' => 'card'],
            ['days_ago' => 2, 'amount' => 290.50, 'method' => 'cash'],
            ['days_ago' => 1, 'amount' => 380.00, 'method' => 'card'],
        ];

        foreach ($historicalData as $hist) {
            $histDate = now()->subDays($hist['days_ago'])->setHour(19)->setMinute(30);

            Payment::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $milanBranch->id,
                'amount' => $hist['amount'],
                'currency' => 'EUR',
                'payment_method' => $hist['method'],
                'payment_type' => 'full',
                'status' => 'completed',
                'transaction_reference' => 'TXN-HIST-' . $hist['days_ago'],
                'notes' => 'Settlement for dinner shift (' . $hist['days_ago'] . ' days ago)',
                'created_at' => $histDate,
                'updated_at' => $histDate,
            ]);

            Order::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $milanBranch->id,
                'order_number' => '#H' . (900 + $hist['days_ago']),
                'order_type' => 'dine_in',
                'status' => 'completed',
                'guest_name' => 'Historical Diner',
                'subtotal' => round($hist['amount'] * 0.92, 2),
                'tax' => round($hist['amount'] * 0.08, 2),
                'total' => $hist['amount'],
                'payment_status' => 'completed',
                'created_at' => $histDate,
                'updated_at' => $histDate,
            ]);
        }

        // 5c. Seed Inventory Ingredients and Stock Tracking
        $inventorySeed = [
            ['item_name' => 'Wagyu Beef (A5 Cut)', 'category' => 'meat', 'current_stock' => 2.00, 'minimum_stock' => 5.00, 'unit' => 'kg', 'cost_per_unit' => 45.00, 'supplier' => 'Premium Meats Co.'],
            ['item_name' => 'Truffle Oil Extra Virgin', 'category' => 'produce', 'current_stock' => 1.00, 'minimum_stock' => 3.00, 'unit' => 'bottles', 'cost_per_unit' => 28.00, 'supplier' => 'Gourmet Imports'],
            ['item_name' => 'Fresh Atlantic Salmon', 'category' => 'seafood', 'current_stock' => 3.50, 'minimum_stock' => 8.00, 'unit' => 'kg', 'cost_per_unit' => 22.00, 'supplier' => 'Ocean Fresh Ltd.'],
            ['item_name' => 'Buffalo Mozzarella DOP', 'category' => 'dairy', 'current_stock' => 4.00, 'minimum_stock' => 6.00, 'unit' => 'kg', 'cost_per_unit' => 12.00, 'supplier' => 'Dairy Direct'],
            ['item_name' => 'Fresh Organic Basil', 'category' => 'produce', 'current_stock' => 0.50, 'minimum_stock' => 2.00, 'unit' => 'kg', 'cost_per_unit' => 6.00, 'supplier' => 'Herb Garden Co.'],
            ['item_name' => 'All-Purpose Italian Flour 00', 'category' => 'dry_goods', 'current_stock' => 25.00, 'minimum_stock' => 10.00, 'unit' => 'kg', 'cost_per_unit' => 1.80, 'supplier' => "Baker's Best"],
            ['item_name' => 'Mascarpone Cheese DOP', 'category' => 'dairy', 'current_stock' => 8.00, 'minimum_stock' => 4.00, 'unit' => 'kg', 'cost_per_unit' => 6.50, 'supplier' => 'Dairy Direct'],
            ['item_name' => 'Heavy Whipping Cream', 'category' => 'dairy', 'current_stock' => 6.00, 'minimum_stock' => 4.00, 'unit' => 'L', 'cost_per_unit' => 4.50, 'supplier' => 'Dairy Direct'],
            ['item_name' => 'Espresso Coffee Beans', 'category' => 'beverages', 'current_stock' => 18.00, 'minimum_stock' => 10.00, 'unit' => 'kg', 'cost_per_unit' => 16.50, 'supplier' => 'Artisan Roasters'],
        ];

        foreach ($inventorySeed as $inv) {
            InventoryItem::create(array_merge($inv, ['tenant_id' => $tenant->id]));
        }

        // 6. Seed Security Audit Log
        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'action' => 'qr_token_regenerated',
            'payload' => [
                'table_number' => 'Table 7',
                'reason' => 'Scheduled table QR security rotation',
                'old_token' => 'old_7x8b21c',
                'new_token' => 'table-7',
                'version' => 2,
            ],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subHours(3),
        ]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'action' => 'table_session_settled',
            'payload' => [
                'session_id' => 99,
                'table' => 'Table 3',
                'waived_amount' => 0,
                'reason' => 'Full card payment confirmed by floor waiter',
            ],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subHours(2),
        ]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'action' => 'order_cancelled',
            'payload' => [
                'order_number' => '#1018',
                'cancelling_guest' => 'Sara',
                'reason' => 'Guest ordered wrong item before kitchen acceptance',
            ],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinutes(40),
        ]);
    }

    private function seedBranch(Tenant $tenant, Branch $branch): void
    {
        TenantContext::setTenant($tenant);

        // Categories
        $mains = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Mains & Steaks',
            'slug' => 'mains-' . $branch->slug,
            'description' => 'Chef signature grilled steaks and specialty entrees',
            'sort_order' => 1,
        ]);

        $italian = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizza & Pasta',
            'slug' => 'italian-' . $branch->slug,
            'description' => 'Authentic artisan pizzas and handmade pasta',
            'sort_order' => 2,
        ]);

        $desserts = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Desserts',
            'slug' => 'desserts-' . $branch->slug,
            'description' => 'Fresh sweets prepared daily',
            'sort_order' => 3,
        ]);

        // Dishes with high-resolution food photography
        $wagyu = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $mains->id,
            'name' => 'Wagyu Ribeye Steak',
            'description' => 'A5 Japanese Wagyu served with truffle butter and roasted asparagus',
            'price' => 85.00,
            'image_url' => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=800&auto=format&fit=crop&q=80',
            'prep_time_minutes' => 25,
            'is_available' => true,
            'is_featured' => true,
            'calories' => 850,
        ]);

        $salmon = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $mains->id,
            'name' => 'Grilled Atlantic Salmon',
            'description' => 'Pan-seared salmon fillet over lemon-herb quinoa and citrus glaze',
            'price' => 42.50,
            'image_url' => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=800&auto=format&fit=crop&q=80',
            'prep_time_minutes' => 20,
            'is_available' => true,
            'is_featured' => true,
            'calories' => 620,
        ]);

        $pizza = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $italian->id,
            'name' => 'Margherita Classica',
            'description' => 'San Marzano tomatoes, buffalo mozzarella, fresh basil, extra virgin olive oil',
            'price' => 20.00,
            'image_url' => 'https://images.unsplash.com/photo-1604382355076-af4b0eb60143?w=800&auto=format&fit=crop&q=80',
            'prep_time_minutes' => 15,
            'is_available' => true,
            'is_vegetarian' => true,
            'calories' => 740,
        ]);

        $truffle = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $italian->id,
            'name' => 'Black Truffle Tagliatelle',
            'description' => 'Handmade pasta tossed in aged parmesan and black winter truffle butter',
            'price' => 45.00,
            'image_url' => 'https://images.unsplash.com/photo-1621996346565-e3d5d6281292?w=800&auto=format&fit=crop&q=80',
            'prep_time_minutes' => 18,
            'is_available' => true,
            'is_vegetarian' => true,
            'is_featured' => true,
            'calories' => 680,
        ]);

        $tiramisu = MenuItem::create([
            'tenant_id' => $tenant->id,
            'category_id' => $desserts->id,
            'name' => 'Traditional Tiramisu',
            'description' => 'Espresso-soaked ladyfingers, velvety mascarpone cream, and Dutch cocoa',
            'price' => 14.00,
            'image_url' => 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=800&auto=format&fit=crop&q=80',
            'prep_time_minutes' => 5,
            'is_available' => true,
            'calories' => 450,
        ]);

        // Tables with secure random QR tokens (as requested in spec: e.g. 8f3k2x)
        $t1 = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'Table 1',
            'name' => 'Window Corner 1',
            'capacity' => 2,
            'zone' => 'Window',
            'status' => 'available',
            'qr_token' => $branch->slug === 'milan' ? '8f3k2x' : 'r1_' . Str::random(6),
        ]);

        $t7 = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'Table 7',
            'name' => 'Main Center 7',
            'capacity' => 4,
            'zone' => 'Main',
            'status' => 'occupied',
            'qr_token' => $branch->slug === 'milan' ? 'table-7' : 'r7_' . Str::random(6),
        ]);

        $t5 = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'Table 5',
            'name' => 'VIP Lounge 5',
            'capacity' => 6,
            'zone' => 'VIP',
            'status' => 'billing',
            'qr_token' => $branch->slug === 'milan' ? 'vip-5' : 'r5_' . Str::random(6),
        ]);

        $t8 = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'Table 8',
            'name' => 'Garden Terrace 8',
            'capacity' => 2,
            'zone' => 'Patio',
            'status' => 'cleaning',
            'qr_token' => $branch->slug === 'milan' ? 'patio-8' : 'r8_' . Str::random(6),
        ]);

        // Active Table Session for Table 7 (Shared tab) - Milan branch only
        if ($branch->slug === 'milan') {
            $session = TableSession::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'table_id' => $t7->id,
                'session_token' => 'sess_' . $branch->slug . '_7',
                'status' => 'active',
                'opened_at' => now()->subMinutes(35),
                'subtotal' => 105.00,
                'tax' => 10.50,
                'service_charge' => 5.25,
                'total' => 120.75,
            ]);

            $order1 = Order::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'session_id' => $session->id,
                'table_id' => $t7->id,
                'guest_name' => 'Abel',
                'order_number' => '#1024',
                'order_type' => 'dine_in',
                'status' => 'preparing',
                'subtotal' => 85.00,
                'tax' => 8.50,
                'service_charge' => 4.25,
                'total' => 97.75,
                'payment_choice' => 'pay_later',
            ]);

            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order1->id,
                'menu_item_id' => $wagyu->id,
                'item_name' => 'Wagyu Ribeye Steak',
                'unit_price' => 85.00,
                'quantity' => 1,
                'subtotal' => 85.00,
                'status' => 'preparing',
                'special_notes' => 'Medium rare please',
            ]);

            $order2 = Order::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'session_id' => $session->id,
                'table_id' => $t7->id,
                'guest_name' => 'Sara',
                'order_number' => '#1025',
                'order_type' => 'dine_in',
                'status' => 'placed',
                'subtotal' => 20.00,
                'tax' => 2.00,
                'service_charge' => 1.00,
                'total' => 23.00,
                'payment_choice' => 'pay_later',
            ]);

            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order2->id,
                'menu_item_id' => $pizza->id,
                'item_name' => 'Margherita Classica',
                'unit_price' => 20.00,
                'quantity' => 1,
                'subtotal' => 20.00,
                'status' => 'placed',
            ]);
        }
    }
}
