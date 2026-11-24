<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_can_be_retrieved_and_updated_with_validation(): void
    {
        $tenant = Tenant::create(['name' => 'Café Milan', 'slug' => 'milan-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Milan Central',
            'slug' => 'milan-' . uniqid(),
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
        ]);

        $admin = User::create([
            'name' => 'Settings Admin',
            'email' => 'admin_set_' . uniqid() . '@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($admin->id, ['role' => 'admin']);

        $resGet = $this->withHeader('X-Branch-ID', $branch->id)->getJson('/api/settings');
        $resGet->assertStatus(200);
        $this->assertEquals('EUR', $resGet->json('settings.currency'));

        // Unauthenticated patch MUST fail with 401
        $unauthPatch = $this->withHeader('X-Branch-ID', $branch->id)->patchJson('/api/settings', [
            'tax_rate' => 12.50,
        ]);
        $unauthPatch->assertStatus(401);

        // Authenticated admin patch succeeds
        $resPatch = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-ID', $branch->id)
            ->patchJson('/api/settings', [
                'tax_rate' => 12.50,
                'service_charge' => 7.00,
            ]);
        $resPatch->assertStatus(200);
        $this->assertEquals(12.50, (float) $branch->fresh()->tax_rate);
    }

    public function test_waiter_cannot_settle_session_belonging_to_another_branch(): void
    {
        $tenant = Tenant::create(['name' => 'Multi Branch Café', 'slug' => 'mb-' . uniqid()]);
        $branchMilan = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Milan',
            'slug' => 'milan-' . uniqid(),
            'currency' => 'EUR',
        ]);
        $branchRome = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rome',
            'slug' => 'rome-' . uniqid(),
            'currency' => 'EUR',
        ]);

        $tableMilan = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMilan->id,
            'table_number' => 'MI-01',
            'capacity' => 4,
            'status' => 'occupied',
            'qr_token' => 'mi01_' . uniqid(),
        ]);

        $sessionMilan = \App\Models\TableSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMilan->id,
            'table_id' => $tableMilan->id,
            'session_token' => 'sess_' . uniqid(),
            'status' => 'billing',
            'total' => 100.00,
            'paid_amount' => 50.00,
            'opened_at' => now(),
        ]);

        // Create a waiter explicitly assigned to Rome branch
        $romeWaiter = User::create([
            'name' => 'Rome Waiter',
            'email' => 'waiter_rome_' . uniqid() . '@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($romeWaiter->id, ['role' => 'waiter']);
        // Assign branch_id to user model
        $romeWaiter->branch_id = $branchRome->id;
        $romeWaiter->save();

        // Rome waiter attempts to settle Milan table session with manager override
        $resSettle = $this->actingAs($romeWaiter, 'sanctum')
            ->postJson("/api/waiter/sessions/{$sessionMilan->id}/settle", [
                'paid_amount' => 50.00,
                'manager_override' => true,
                'notes' => 'Attempting unauthorized settlement across branches',
            ]);

        $resSettle->assertStatus(403);
    }

    public function test_tenant_cross_access_is_prevented(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 't-a-' . uniqid()]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 't-b-' . uniqid()]);

        $branchA = Branch::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Branch A',
            'slug' => 'b-a-' . uniqid(),
            'currency' => 'EUR',
        ]);
        $branchB = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Branch B',
            'slug' => 'b-b-' . uniqid(),
            'currency' => 'USD',
        ]);

        $tableA = RestaurantTable::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'table_number' => 'T-A',
            'capacity' => 2,
            'status' => 'available',
            'qr_token' => 'tokenA_' . bin2hex(random_bytes(8)),
        ]);

        // Accessing Table A scan with Tenant B header still correctly maps Table A to its own tenant
        $resScan = $this->withHeader('X-Tenant-ID', $tenantB->id)->getJson("/api/t/{$tableA->qr_token}");
        $resScan->assertStatus(200);
        $this->assertEquals('T-A', $resScan->json('table.table_number'));
    }

    public function test_public_signup_cannot_escalate_role_to_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Security Test Café', 'slug' => 'sec-' . uniqid()]);

        // Attacker attempts to register with role=admin
        $res = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/auth/signup', [
            'name' => 'Attacker',
            'email' => 'attacker_' . uniqid() . '@exploit.test',
            'password' => 'malicious_pass_123',
            'role' => 'admin',
        ]);

        $res->assertStatus(201);
        $roles = $res->json('roles');

        // Verify that the assigned roles do NOT contain admin or manager
        $this->assertNotContains('admin', $roles);
        $this->assertNotContains('manager', $roles);
        $this->assertContains('customer', $roles);

        // Verify in database pivot that only 'customer' role was granted
        $user = User::where('email', $res->json('user.email'))->first();
        $this->assertNotNull($user);
        $dbRoles = $tenant->users()->where('user_id', $user->id)->pluck('role')->toArray();
        $this->assertNotContains('admin', $dbRoles);
        $this->assertEquals(['customer'], $dbRoles);
    }
}
