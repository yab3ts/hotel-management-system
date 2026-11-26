<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityChecklistVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoTenants(): array
    {
        $tenantA = Tenant::create(['name' => 'Hotel Alpha', 'slug' => 'alpha-' . uniqid()]);
        $branchA = Branch::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alpha Main',
            'slug' => 'alpha-b-' . uniqid(),
            'currency' => 'USD',
        ]);
        $adminA = User::create([
            'name' => 'Alpha Admin',
            'email' => 'admin_a_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $tenantA->id,
            'is_active' => true,
        ]);
        $tenantA->users()->attach($adminA->id, ['role' => 'admin']);

        $tenantB = Tenant::create(['name' => 'Hotel Beta', 'slug' => 'beta-' . uniqid()]);
        $branchB = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Beta Main',
            'slug' => 'beta-b-' . uniqid(),
            'currency' => 'EUR',
        ]);
        $adminB = User::create([
            'name' => 'Beta Admin',
            'email' => 'admin_b_' . uniqid() . '@beta.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $tenantB->id,
            'is_active' => true,
        ]);
        $tenantB->users()->attach($adminB->id, ['role' => 'admin']);

        return compact('tenantA', 'branchA', 'adminA', 'tenantB', 'branchB', 'adminB');
    }

    public function test_auth_hardening_defaults_bruteforce_weak_password_and_disabled_staff(): void
    {
        $ctx = $this->seedTwoTenants();

        // #1 Default passwords rejected
        $this->postJson('/api/auth/login', ['email' => 'admin@cafex.com', 'password' => 'admin'])->assertStatus(401);

        // #9 & #3 Weak + breached password policy rejects "123456" and "Password123"
        $this->postJson('/api/auth/signup', [
            'email' => 'weak_' . uniqid() . '@test.com',
            'password' => '123456',
            'name' => 'Weak User',
        ])->assertStatus(422);

        $this->postJson('/api/auth/signup', [
            'email' => 'breached_' . uniqid() . '@test.com',
            'password' => 'Password123',
            'name' => 'Breached User',
        ])->assertStatus(422);

        // #77 Password stored using bcrypt (not plain text or MD5)
        $this->assertStringStartsWith('$2', $ctx['adminA']->password);

        // #2 & #3 Brute-force + CAPTCHA gate: 3 failures trigger CAPTCHA (428), 5 total lock out (429)
        $targetEmail = $ctx['adminA']->email;
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $targetEmail,
                'password' => 'WrongPass999',
            ])->assertStatus(401);
        }
        // 4th attempt without CAPTCHA fails with 428 captcha_required
        $this->postJson('/api/auth/login', [
            'email' => $targetEmail,
            'password' => 'WrongPass999',
        ])->assertStatus(428)->assertJsonPath('captcha_required', true);

        // 5th attempt with valid CAPTCHA answer still checks password -> 401
        $cap = $this->getJson('/api/auth/captcha')->assertStatus(200)->json();
        preg_match('/(\d+)\s*\+\s*(\d+)/', $cap['question'], $m);
        $ans = (string) ((int) $m[1] + (int) $m[2]);
        $this->postJson('/api/auth/login', [
            'email' => $targetEmail,
            'password' => 'WrongPass999',
            'captcha_token' => $cap['captcha_token'],
            'captcha_answer' => $ans,
        ])->assertStatus(401);

        // 6th attempt hits hard lockout -> 429
        $this->postJson('/api/auth/login', [
            'email' => $targetEmail,
            'password' => 'StrongPass123',
        ])->assertStatus(429);

        // #12 Former / disabled employee rejected immediately
        $disabledStaff = User::create([
            'name' => 'Terminated Staff',
            'email' => 'ex_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
            'is_active' => false,
        ]);
        $ctx['tenantA']->users()->attach($disabledStaff->id, ['role' => 'waiter']);

        $this->postJson('/api/auth/login', [
            'email' => $disabledStaff->email,
            'password' => 'StrongPass123',
        ])->assertStatus(403);

        $this->actingAs($disabledStaff, 'sanctum')
            ->getJson('/api/waiter/tables')
            ->assertStatus(403);
    }

    public function test_logout_invalidates_token_and_password_reset_is_secure_and_non_enumerable(): void
    {
        $ctx = $this->seedTwoTenants();

        // Login and capture real Sanctum bearer token
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => $ctx['adminA']->email,
            'password' => 'StrongPass123',
        ]);
        $loginRes->assertStatus(200);
        $token = $loginRes->json('token');

        // #7 Sanctum expiration is configured (not null)
        $this->assertGreaterThan(0, config('sanctum.expiration'));

        // #8 Logout invalidates token; reusing old token returns 401
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);

        // #15 / #16 Tampered / invalid bearer token rejected
        $this->withToken('invalid.unsigned.token')->getJson('/api/auth/me')->assertStatus(401);

        // #11 Password reset returns identical message for existing vs non-existing emails
        $existingReset = $this->postJson('/api/auth/forgot-password', ['email' => $ctx['adminA']->email]);
        $nonExistingReset = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody_' . uniqid() . '@nowhere.test']);
        $existingReset->assertStatus(200);
        $nonExistingReset->assertStatus(200);
        $this->assertEquals($existingReset->json('message'), $nonExistingReset->json('message'));

        // #10 Reset token expires after 15 mins and is strictly one-time
        $plainToken = bin2hex(random_bytes(32));
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $ctx['adminA']->email],
            ['token' => Hash::make($plainToken), 'created_at' => now()->subMinutes(20)]
        );
        $this->postJson('/api/auth/reset-password', [
            'email' => $ctx['adminA']->email,
            'token' => $plainToken,
            'password' => 'NewValidPass123',
        ])->assertStatus(422);

        // Fresh valid token succeeds once, then fails on replay
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $ctx['adminA']->email],
            ['token' => Hash::make($plainToken), 'created_at' => now()]
        );
        $this->postJson('/api/auth/reset-password', [
            'email' => $ctx['adminA']->email,
            'token' => $plainToken,
            'password' => 'NewValidPass123',
        ])->assertStatus(200);

        $this->postJson('/api/auth/reset-password', [
            'email' => $ctx['adminA']->email,
            'token' => $plainToken,
            'password' => 'AnotherPass123',
        ])->assertStatus(422);
    }

    public function test_rbac_idor_forced_browsing_and_method_tampering(): void
    {
        $ctx = $this->seedTwoTenants();

        $customer = User::create([
            'name' => 'Normal Guest',
            'email' => 'guest_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($customer->id, ['role' => 'customer']);

        $waiter = User::create([
            'name' => 'Floor Waiter',
            'email' => 'waiter_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($waiter->id, ['role' => 'waiter']);

        // #62 Unauthenticated access blocked with 401
        $this->getJson('/api/payments')->assertStatus(401);
        $this->getJson('/api/dashboard/stats')->assertStatus(401);
        $this->getJson('/api/audit-logs')->assertStatus(401);
        $this->getJson('/api/waiter/tables')->assertStatus(401);

        // #22, #23, #24, #25 Vertical privilege escalation blocked with 403
        $this->actingAs($customer, 'sanctum')->getJson('/api/dashboard/stats')->assertStatus(403);
        $this->actingAs($customer, 'sanctum')->getJson('/api/audit-logs')->assertStatus(403);
        $this->actingAs($waiter, 'sanctum')->getJson('/api/payments')->assertStatus(403);
        $this->actingAs($waiter, 'sanctum')->getJson('/api/reports/reconciliation')->assertStatus(403);
        $this->actingAs($waiter, 'sanctum')->patchJson('/api/settings', ['tax_rate' => 1])->assertStatus(403);

        // #26 Forced browsing returns 404
        $this->getJson('/api/reports/export')->assertStatus(404);
        $this->get('/backup.zip')->assertStatus(404);

        // #28 HTTP method tampering returns 405
        $this->deleteJson('/api/health')->assertStatus(405);
        $this->putJson('/api/menu')->assertStatus(405);

        // #19 IDOR on receipt blocked with 403
        $tableA = RestaurantTable::create([
            'tenant_id' => $ctx['tenantA']->id,
            'branch_id' => $ctx['branchA']->id,
            'table_number' => 'T1',
            'capacity' => 2,
            'qr_token' => 'tok_' . bin2hex(random_bytes(16)),
        ]);
        $sessionA = TableSession::create([
            'tenant_id' => $ctx['tenantA']->id,
            'branch_id' => $ctx['branchA']->id,
            'table_id' => $tableA->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(16)),
            'status' => 'active',
            'opened_at' => now(),
        ]);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/sessions/{$sessionA->id}/receipt")->assertStatus(403);
        $crossReceipt = $this->actingAs($ctx['adminB'], 'sanctum')->getJson("/api/sessions/{$sessionA->id}/receipt");
        $this->assertContains($crossReceipt->status(), [403, 404]);
        $this->actingAs($ctx['adminA'], 'sanctum')->getJson("/api/sessions/{$sessionA->id}/receipt")->assertStatus(200);

        // #18 IDOR on reservations across tenants blocked
        $resB = Reservation::create([
            'tenant_id' => $ctx['tenantB']->id,
            'reservation_number' => 'RES-' . uniqid(),
            'guest_name' => 'VIP Guest',
            'guest_email' => 'vip@beta.test',
            'guest_count' => 2,
            'reservation_date' => now()->addDay()->toDateString(),
            'reservation_time' => '19:00',
            'status' => 'confirmed',
        ]);
        $crossRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->patchJson("/api/reservations/{$resB->id}/status", ['status' => 'cancelled']);
        $this->assertContains($crossRes->status(), [403, 404]);
    }

    public function test_injections_ssrf_headers_cors_and_business_logic_validation(): void
    {
        $ctx = $this->seedTwoTenants();

        // #31 & #32 SQL injection treated as literal text
        $this->getJson('/api/menu?search=' . urlencode("' OR 1=1--"))->assertStatus(200);
        $this->getJson('/api/menu?search=' . urlencode("'; SELECT SLEEP(5);--"))->assertStatus(200);

        // #38 NoSQL injection rejected
        $this->postJson('/api/auth/login', [
            'email' => ['$ne' => null],
            'password' => ['$ne' => null],
        ])->assertStatus(422);

        // #40 SSRF metadata URL rejected in MenuController & SettingsController
        $cat = MenuCategory::create(['tenant_id' => $ctx['tenantA']->id, 'name' => 'Mains', 'slug' => 'mains-' . uniqid()]);
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->postJson('/api/menu', [
                'category_id' => $cat->id,
                'name' => 'Test Dish',
                'price' => 15.00,
                'image_url' => 'http://169.254.169.254/latest/meta-data/',
            ])->assertStatus(422);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->patchJson('/api/settings', [
                'qr_base_url' => 'http://169.254.169.254/latest/meta-data/',
            ])->assertStatus(422);

        // #52 Negative price rejected
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->postJson('/api/menu', [
                'category_id' => $cat->id,
                'name' => 'Negative Dish',
                'price' => -50.00,
            ])->assertStatus(422);

        // #33 XSS & #47 Template injection escaped/stripped & stored literally
        $xssRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->postJson('/api/menu', [
                'category_id' => $cat->id,
                'name' => '<script>alert(1)</script>Pasta {{7*7}}',
                'price' => 18.00,
            ]);
        $xssRes->assertStatus(201);
        $this->assertEquals('alert(1)Pasta {{7*7}}', $xssRes->json('name'));

        // #50 Double booking & #53 Past date manipulation
        $tableA = RestaurantTable::create([
            'tenant_id' => $ctx['tenantA']->id,
            'branch_id' => $ctx['branchA']->id,
            'table_number' => 'RES-T1',
            'capacity' => 4,
            'qr_token' => 'restok_' . bin2hex(random_bytes(16)),
        ]);
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/reservations', [
                'table_id' => $tableA->id,
                'guest_name' => 'Past Diner',
                'guest_email' => 'past@test.com',
                'guest_count' => 2,
                'reservation_date' => now()->subDay()->toDateString(),
                'reservation_time' => '19:00',
            ])->assertStatus(422);

        $futureDate = now()->addDays(2)->toDateString();
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/reservations', [
                'table_id' => $tableA->id,
                'guest_name' => 'First Diner',
                'guest_email' => 'first@test.com',
                'guest_count' => 2,
                'reservation_date' => $futureDate,
                'reservation_time' => '20:00',
            ])->assertStatus(201);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/reservations', [
                'table_id' => $tableA->id,
                'guest_name' => 'Second Diner',
                'guest_email' => 'second@test.com',
                'guest_count' => 2,
                'reservation_date' => $futureDate,
                'reservation_time' => '20:00',
            ])->assertStatus(422);

        // #41 Path traversal & #48 CRLF injection
        $this->get('/api/../../etc/passwd')->assertStatus(404);
        $this->get('/api/menu?search=test%0d%0aSet-Cookie:evil=1')->assertStatus(400);

        // #17, #46, #87, #99 Security headers check
        $healthRes = $this->getJson('/api/health');
        $healthRes->assertStatus(200);
        $healthRes->assertHeader('X-Frame-Options', 'DENY');
        $healthRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $healthRes->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $healthRes->headers->get('Cache-Control'));
        $this->assertFalse($healthRes->headers->has('X-Powered-By'));

        // #67 CORS denies evil origin
        $corsRes = $this->withHeader('Origin', 'https://evil.com')->getJson('/api/health');
        $this->assertNotEquals('https://evil.com', $corsRes->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotEquals('*', $corsRes->headers->get('Access-Control-Allow-Origin'));

        // #126 Audit logs are immutable
        $log = AuditLog::create([
            'tenant_id' => $ctx['tenantA']->id,
            'user_id' => $ctx['adminA']->id,
            'action' => 'security_test_event',
            'payload' => ['ok' => true],
        ]);
        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    public function test_multitenant_isolation_header_tampering_onboarding_and_qr_bypass_prevention(): void
    {
        $ctx = $this->seedTwoTenants();

        // Guessable table number / ID cannot bypass cryptographic QR token
        $tableA = RestaurantTable::create([
            'tenant_id' => $ctx['tenantA']->id,
            'branch_id' => $ctx['branchA']->id,
            'table_number' => '101',
            'capacity' => 4,
            'qr_token' => 'crypto_' . bin2hex(random_bytes(16)),
        ]);
        $this->getJson('/api/t/101')->assertStatus(404);
        $this->getJson("/api/t/{$tableA->id}")->assertStatus(404);
        $this->getJson("/api/t/{$tableA->qr_token}")->assertStatus(200);

        // M2 & M6: Tenant A admin cannot access Tenant B via X-Tenant-ID or switch-tenant
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantB']->id)
            ->getJson('/api/dashboard/stats')
            ->assertStatus(403);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/auth/switch-tenant', ['tenant_id' => $ctx['tenantB']->id])
            ->assertStatus(403);

        // M9 & M20: Audit logs strictly isolated per tenant
        AuditLog::create([
            'tenant_id' => $ctx['tenantB']->id,
            'user_id' => $ctx['adminB']->id,
            'action' => 'secret_beta_action',
            'payload' => ['secret' => 'beta_only'],
        ]);

        $logsA = $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->getJson('/api/audit-logs')
            ->assertStatus(200)
            ->json();

        $this->assertEmpty(array_filter($logsA, fn ($l) => ($l['action'] ?? '') === 'secret_beta_action'));

        // M14: Duplicate tenant name & reserved slugs rejected
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/tenants', ['name' => 'Hotel Beta', 'slug' => 'new-beta'])
            ->assertStatus(422);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/tenants', ['name' => 'Admin Corp', 'slug' => 'admin'])
            ->assertStatus(422);
    }

    public function test_mfa_totp_setup_enforcement_and_recovery_codes(): void
    {
        $ctx = $this->seedTwoTenants();
        $admin = $ctx['adminA'];

        // 1. Setup MFA
        $setupRes = $this->actingAs($admin, 'sanctum')->postJson('/api/auth/mfa/setup');
        $setupRes->assertStatus(200)->assertJsonStructure(['secret', 'otpauth_uri', 'recovery_codes']);
        $secret = $setupRes->json('secret');
        $recoveryCode = $setupRes->json('recovery_codes.0');

        // 2. Enabling with wrong code fails
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/auth/mfa/enable', ['totp_code' => '000000'])
            ->assertStatus(422);

        // 3. Enabling with valid RFC 6238 TOTP code succeeds (use slice -1 so current slice remains unused for login test)
        $enableCode = \App\Services\TotpService::getCode($secret, ((int) floor(time() / 30)) - 1);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/auth/mfa/enable', ['totp_code' => $enableCode])
            ->assertStatus(200);

        // 4. Password-only login is now blocked with mfa_required=true and no token
        $this->app['auth']->forgetGuards();
        $noMfaLogin = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass123',
        ]);
        $noMfaLogin->assertStatus(403)->assertJsonPath('mfa_required', true);
        $this->assertNull($noMfaLogin->json('token'));

        // 5. Login with current valid TOTP code succeeds & issues token
        $loginCode = \App\Services\TotpService::getCode($secret);
        $okLogin = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass123',
            'totp_code' => $loginCode,
        ]);
        $okLogin->assertStatus(200);
        $this->assertNotEmpty($okLogin->json('token'));

        // 6. Replaying the same TOTP code within the same time window is rejected
        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass123',
            'totp_code' => $loginCode,
        ])->assertStatus(403);

        // 7. One-time recovery code succeeds once, then cannot be reused
        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass123',
            'recovery_code' => $recoveryCode,
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass123',
            'recovery_code' => $recoveryCode,
        ])->assertStatus(403);
    }

    public function test_encrypted_backup_offserver_upload_and_restore_drill(): void
    {
        $tmpDir = sys_get_temp_dir() . '/cafex_sec_backup_' . uniqid();
        \Illuminate\Support\Facades\File::makeDirectory($tmpDir, 0750, true);
        \Illuminate\Support\Facades\Storage::fake('s3');

        try {
            if (config('database.default') === 'pgsql') {
                // Real PostgreSQL pg_dump -> AES-256-GCM encryption -> S3 upload -> checksum+tag decrypt
                $exitBackup = \Illuminate\Support\Facades\Artisan::call('cafex:backup', [
                    '--path' => $tmpDir,
                    '--remote-disk' => 's3',
                ]);
                $this->assertEquals(0, $exitBackup, \Illuminate\Support\Facades\Artisan::output());

                $encFiles = glob("{$tmpDir}/*.sql.enc");
                $this->assertCount(1, $encFiles);
                $encContent = file_get_contents($encFiles[0]);
                $this->assertStringStartsWith('CFXB', $encContent);
                $this->assertStringNotContainsString('CREATE TABLE', $encContent);
                $this->assertCount(2, \Illuminate\Support\Facades\Storage::disk('s3')->allFiles('backups'));
                return;
            }

            $sourceDb = $tmpDir . '/source.sqlite';
            \Illuminate\Support\Facades\File::put($sourceDb, 'SQLITE_DRILL_PAYLOAD_SECRET_DATA');
            config(['database.connections.sqlite.database' => $sourceDb]);

            $exitBackup = \Illuminate\Support\Facades\Artisan::call('cafex:backup', [
                '--path' => $tmpDir,
                '--remote-disk' => 's3',
            ]);
            $this->assertEquals(0, $exitBackup);

            $encFiles = glob("{$tmpDir}/*.enc");
            $this->assertCount(1, $encFiles);
            $encContent = file_get_contents($encFiles[0]);
            $this->assertStringStartsWith('CFXB', $encContent);
            $this->assertStringNotContainsString('SQLITE_DRILL_PAYLOAD_SECRET_DATA', $encContent);

            // Verify off-server S3 upload received both .enc and .json metadata
            $remoteFiles = \Illuminate\Support\Facades\Storage::disk('s3')->allFiles('backups');
            $this->assertCount(2, $remoteFiles);

            // Wipe source DB (simulate ransomware) and run restore drill
            file_put_contents($sourceDb, 'CORRUPTED_BY_RANSOMWARE');
            $exitRestore = \Illuminate\Support\Facades\Artisan::call('cafex:restore', [
                '--path' => $tmpDir,
                '--force' => true,
            ]);
            $this->assertEquals(0, $exitRestore, \Illuminate\Support\Facades\Artisan::output());
            $this->assertEquals('SQLITE_DRILL_PAYLOAD_SECRET_DATA', file_get_contents($sourceDb));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($tmpDir);
        }
    }

    public function test_gdpr_privacy_retention_hold_expiry_staff_deactivation_and_tenant_offboarding(): void
    {
        $ctx = $this->seedTwoTenants();

        // #13 & #12 Staff deactivation via admin endpoint
        $waiter = User::create([
            'name' => 'Staff Alice',
            'email' => 'alice_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
            'is_active' => true,
        ]);
        $ctx['tenantA']->users()->attach($waiter->id, ['role' => 'waiter']);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->getJson('/api/staff')
            ->assertStatus(200);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->patchJson("/api/staff/{$waiter->id}/status", ['is_active' => false])
            ->assertStatus(200);
        $this->assertFalse($waiter->fresh()->is_active);

        // #59 Reservation hold auto-expiry (>30 mins pending -> cancelled)
        $staleHold = Reservation::create([
            'tenant_id' => $ctx['tenantA']->id,
            'reservation_number' => 'HOLD-' . uniqid(),
            'guest_name' => 'Hold Guest',
            'guest_email' => 'hold@test.com',
            'guest_count' => 2,
            'reservation_date' => now()->addDay()->toDateString(),
            'reservation_time' => '18:00',
            'status' => 'pending',
        ]);
        DB::table('reservations')->where('id', $staleHold->id)->update(['created_at' => now()->subMinutes(45)]);
        \Illuminate\Support\Facades\Artisan::call('reservations:expire-holds');
        $this->assertEquals('cancelled', $staleHold->fresh()->status);

        // #83 90-day privacy audit retention purge
        $oldLog = AuditLog::create([
            'tenant_id' => $ctx['tenantA']->id,
            'action' => 'old_event',
            'payload' => [],
        ]);
        DB::table('audit_logs')->where('id', $oldLog->id)->update(['created_at' => now()->subDays(95)]);
        \Illuminate\Support\Facades\Artisan::call('privacy:purge-retention');
        $this->assertNull(AuditLog::withoutGlobalScopes()->find($oldLog->id));

        // #84 GDPR export & erasure
        $guest = User::create([
            'name' => 'GDPR User',
            'email' => 'gdpr_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($guest->id, ['role' => 'customer']);

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/privacy/export')
            ->assertStatus(200)
            ->assertJsonPath('profile.email', $guest->email);

        $this->actingAs($guest, 'sanctum')
            ->deleteJson('/api/privacy/erasure', ['password' => 'StrongPass123'])
            ->assertStatus(200);
        $this->assertStringContainsString('@anonymized.local', $guest->fresh()->email);
        $this->assertFalse($guest->fresh()->is_active);

        // M21 Domain verification challenge issues DNS TXT challenge
        $domainRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/tenants/domain-challenge', ['domain' => 'bookings.alpha-hotel.example']);
        $domainRes->assertStatus(200)->assertJsonPath('verified', false);
        $this->assertStringStartsWith('tableza-verify=', $domainRes->json('txt_record_value'));

        // M23 & M15 Per-tenant export and offboarding purge (leaves Tenant B untouched)
        $exportDir = sys_get_temp_dir() . '/cafex_tenant_export_' . uniqid();
        try {
            \Illuminate\Support\Facades\Artisan::call('tenant:export', [
                'tenant_id' => $ctx['tenantA']->id,
                '--path' => $exportDir,
            ]);
            $this->assertCount(1, glob("{$exportDir}/*.json"));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($exportDir);
        }

        \Illuminate\Support\Facades\Artisan::call('tenant:purge', [
            'tenant_id' => $ctx['tenantA']->id,
            '--force' => true,
        ]);
        $this->assertNull(Tenant::find($ctx['tenantA']->id));
        $this->assertNotNull(Tenant::find($ctx['tenantB']->id));
    }

    public function test_wildcard_subdomain_routing_cors_and_cross_tenant_enumeration_m22(): void
    {
        $ctx = $this->seedTwoTenants();
        $slugA = $ctx['tenantA']->slug;
        $slugB = $ctx['tenantB']->slug;

        // Wildcard subdomain resolves Tenant A and Tenant B automatically from Host header
        $this->getJson("http://{$slugA}.tableza.app/api/menu")
            ->assertStatus(200)
            ->assertHeader('X-Tenant-Slug', $slugA);

        $this->getJson("http://{$slugB}.tableza.app/api/menu")
            ->assertStatus(200)
            ->assertHeader('X-Tenant-Slug', $slugB);

        // Unknown wildcard subdomain returns 404 instead of leaking Tenant 1
        $this->getJson('http://nonexistent-hotel.tableza.app/api/menu')
            ->assertStatus(404);

        // Wildcard subdomain CORS allows tenant origins and rejects evil.com
        $this->withHeader('Origin', "https://{$slugB}.tableza.vercel.app")
            ->getJson('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', "https://{$slugB}.tableza.vercel.app");
        $this->flushHeaders();

        // M22: Registering on Tenant A with an email already used on Tenant B returns generic 202 with NO leak/token
        $enumRes = $this->postJson("http://{$slugA}.tableza.app/api/auth/signup", [
            'email' => $ctx['adminB']->email,
            'password' => 'UniquePass999',
            'name' => 'Probe User',
        ]);
        $enumRes->assertStatus(202);
        $this->assertNull($enumRes->json('token'));
    }

    public function test_impersonation_m24_anomalies_vip_offline_sync_per_tenant_crypto_and_jobs(): void
    {
        $ctx = $this->seedTwoTenants();

        // M11: Per-tenant HKDF encryption keys isolate ciphertext between tenants
        $cipherA = \App\Services\TenantCryptoService::encryptForTenant($ctx['tenantA'], 'secret-hotel-a-data');
        $this->assertEquals('secret-hotel-a-data', \App\Services\TenantCryptoService::decryptForTenant($ctx['tenantA'], $cipherA));
        $thrown = false;
        try {
            \App\Services\TenantCryptoService::decryptForTenant($ctx['tenantB'], $cipherA);
        } catch (\RuntimeException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Tenant B must not decrypt Tenant A ciphertext.');

        // M18: Per-tenant gateway webhook secret isolation
        $ctx['tenantA']->update(['webhook_secret' => 'whsec_alpha_' . bin2hex(random_bytes(8))]);
        $ctx['tenantB']->update(['webhook_secret' => 'whsec_beta_' . bin2hex(random_bytes(8))]);

        $payload = json_encode(['id' => 'evt_' . uniqid(), 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['amount_received' => 5000]]]);
        $sigAlpha = hash_hmac('sha256', $payload, $ctx['tenantA']->fresh()->webhook_secret);
        $this->withHeaders([
            'X-Tenant-ID' => $ctx['tenantB']->id,
            'X-Signature' => $sigAlpha,
            'Content-Type' => 'application/json',
        ])->post('/api/webhooks/payment', [], [], [], $payload)->assertStatus(401);
        $this->flushHeaders();

        // M24: Support impersonation requires super_admin + explicit target user consent + 15-min TTL + audit
        $this->actingAs($ctx['adminA'], 'sanctum')->postJson('/api/auth/impersonation/start', [
            'target_user_id' => $ctx['adminB']->id,
            'consent_token' => 'fake',
            'reason' => 'Unauthorized attempt',
        ])->assertStatus(403);

        $support = User::create([
            'name' => 'Platform Support',
            'email' => 'sup_' . uniqid() . '@tableza.com',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
            'is_super_admin' => true,
        ]);
        $ctx['tenantA']->users()->attach($support->id, ['role' => 'customer']);

        // Super admin blocked without target user's explicit consent
        $this->actingAs($support, 'sanctum')->postJson('/api/auth/impersonation/start', [
            'target_user_id' => $ctx['adminA']->id,
            'consent_token' => 'missing-consent',
            'reason' => 'Debugging billing discrepancy ticket #402',
        ])->assertStatus(403);

        // Target user grants consent -> support starts 15-min impersonation -> stops it cleanly
        $consentRes = $this->actingAs($ctx['adminA'], 'sanctum')->postJson('/api/auth/impersonation/grant-consent');
        $consentRes->assertStatus(200);
        $consentToken = $consentRes->json('consent_token');

        $impStart = $this->actingAs($support, 'sanctum')->postJson('/api/auth/impersonation/start', [
            'target_user_id' => $ctx['adminA']->id,
            'consent_token' => $consentToken,
            'reason' => 'Troubleshooting tax calculation ticket #402',
        ]);
        $impStart->assertStatus(200);
        $impToken = $impStart->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($impToken)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertHeader('X-Impersonation-Active', 'true');

        $this->withToken($impToken)->postJson('/api/auth/impersonation/stop')->assertStatus(200);
        $this->app['auth']->forgetGuards();
        $this->withToken($impToken)->getJson('/api/auth/me')->assertStatus(401);

        // #127 After-hours login anomaly raises SecurityAlert
        $waiterA = User::create([
            'name' => 'Night Waiter',
            'email' => 'night_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($waiterA->id, ['role' => 'waiter']);

        config(['app.test_login_hour' => 3]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $waiterA->email, 'password' => 'StrongPass123'])->assertStatus(200);
        config(['app.test_login_hour' => null]);

        // #30 VIP guest reservation viewed by non-admin waiter triggers VIP audit & alert
        Reservation::create([
            'tenant_id' => $ctx['tenantA']->id,
            'reservation_number' => 'VIP-' . uniqid(),
            'guest_name' => 'Celebrity Guest',
            'guest_email' => 'celeb@vip.test',
            'guest_count' => 2,
            'reservation_date' => now()->addDay()->toDateString(),
            'reservation_time' => '21:00',
            'status' => 'confirmed',
            'is_vip' => true,
        ]);
        $this->actingAs($waiterA, 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->getJson('/api/reservations?search=Celebrity')
            ->assertStatus(200);

        // #124 Bulk guest export alert + #133 Security alerts list/resolve + #131/#132 Readiness check
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->getJson('/api/security/export-guests?alert_threshold=1')
            ->assertStatus(200);

        $alertsRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->getJson('/api/security/alerts')
            ->assertStatus(200);

        $categories = array_column($alertsRes->json('alerts'), 'category');
        $this->assertContains('impersonation_started', $categories);
        $this->assertContains('after_hours_login', $categories);
        $this->assertContains('vip_record_accessed', $categories);
        $this->assertContains('bulk_data_export', $categories);

        $firstAlertId = $alertsRes->json('alerts.0.id');
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->patchJson("/api/security/alerts/{$firstAlertId}/resolve")
            ->assertStatus(200);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->getJson('/api/security/readiness')
            ->assertStatus(200)
            ->assertJsonPath('database.healthy', true)
            ->assertJsonPath('incident_response_plan', true)
            ->assertJsonPath('backup_encryption', 'aes-256-gcm');

        // #134 Idempotent offline sync queue recomputes DB prices & deduplicates
        $cat = MenuCategory::create(['tenant_id' => $ctx['tenantA']->id, 'name' => 'OfflineCat', 'slug' => 'off-' . uniqid()]);
        $dish = MenuItem::create(['tenant_id' => $ctx['tenantA']->id, 'category_id' => $cat->id, 'name' => 'Pasta', 'price' => 25.00, 'is_available' => true]);
        $idemKey = 'offline-ord-' . uniqid();

        $syncPayload = [
            'device_id' => 'pos-tablet-01',
            'orders' => [[
                'idempotency_key' => $idemKey,
                'queued_at' => now()->subMinutes(10)->toIso8601String(),
                'guest_name' => 'Offline Table',
                'items' => [['menu_item_id' => $dish->id, 'quantity' => 2, 'price' => 0.01]],
            ]],
        ];

        $sync1 = $this->actingAs($waiterA, 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->postJson('/api/offline/sync', $syncPayload)
            ->assertStatus(200);
        $this->assertEquals(55.00, (float) $sync1->json('synced.0.recomputed_total')); // 50 + 10% tax

        $sync2 = $this->actingAs($waiterA, 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantA']->id)
            ->postJson('/api/offline/sync', $syncPayload)
            ->assertStatus(200);
        $this->assertCount(0, $sync2->json('synced'));
        $this->assertCount(1, $sync2->json('skipped'));

        // M17 & M19: Queued tenant job blocks cross-tenant recipient and logs same-tenant recipient cleanly
        (new \App\Jobs\ProcessTenantNotificationJob($ctx['tenantA']->id, $ctx['adminB']->id, 'bill_ready'))->handle();
        (new \App\Jobs\ProcessTenantNotificationJob($ctx['tenantA']->id, $ctx['adminA']->id, 'bill_ready'))->handle();
        $this->assertFalse(\App\Tenancy\TenantContext::hasTenant());
        $this->assertEquals(1, AuditLog::withoutGlobalScopes()->where('tenant_id', $ctx['tenantA']->id)->where('action', 'tenant_notification_dispatched')->count());
    }

    public function test_iot_door_lock_checkout_key_expiry_stolen_device_wipe_and_ceo_fraud_dual_approval(): void
    {
        $ctx = $this->seedTwoTenants();

        // #115 IoT default credentials rejected (admin123, 1234, etc.)
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/iot/devices', [
                'name' => 'Lobby Door 1',
                'device_type' => 'door_lock',
                'device_identifier' => 'LOCK-01',
                'device_secret' => 'admin123456',
            ])->assertStatus(201); // 11 chars non-default succeeds

        $deviceRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/iot/devices', [
                'name' => 'Suite 202 Lock',
                'device_type' => 'door_lock',
                'device_identifier' => 'LOCK-202',
                'device_secret' => 'hikvision',
            ])->assertStatus(422);

        $lock = \App\Models\IotDevice::create([
            'tenant_id' => $ctx['tenantA']->id,
            'name' => 'Suite 101 Lock',
            'device_type' => 'door_lock',
            'device_identifier' => 'LOCK-101',
            'credential_hash' => Hash::make('StrongLockKey99!'),
            'is_active' => true,
        ]);

        // #111 Door lock API abuse: unauthenticated 401, customer without key 403, cross-tenant 403
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/iot/unlock', ['device_id' => $lock->id])->assertStatus(401);

        $customer = User::create([
            'name' => 'Hotel Guest',
            'email' => 'guest_iot_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($customer->id, ['role' => 'customer']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/iot/unlock', ['device_id' => $lock->id])
            ->assertStatus(403);

        $this->actingAs($ctx['adminB'], 'sanctum')
            ->withHeader('X-Tenant-ID', $ctx['tenantB']->id)
            ->postJson('/api/iot/unlock', ['device_id' => $lock->id])
            ->assertStatus(403);
        $this->flushHeaders();

        // #112 Key issued during active reservation works, then automatically expires on checkout ('completed')
        $res = Reservation::create([
            'tenant_id' => $ctx['tenantA']->id,
            'reservation_number' => 'RES-IOT-' . uniqid(),
            'guest_name' => 'Suite Guest',
            'guest_email' => 'suite@alpha.test',
            'guest_count' => 2,
            'reservation_date' => now()->addDay()->toDateString(),
            'reservation_time' => '15:00',
            'status' => 'confirmed',
        ]);

        $keyRes = $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/iot/keys', ['room_or_table_label' => 'Suite 101', 'reservation_id' => $res->id])
            ->assertStatus(201);
        $plainKey = $keyRes->json('key_token');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/iot/unlock', ['device_id' => $lock->id, 'key_token' => $plainKey])
            ->assertStatus(200);

        // Guest checks out -> key revoked automatically -> unlock fails (403) and issuing new key fails (403)
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->patchJson("/api/reservations/{$res->id}/status", ['status' => 'completed'])
            ->assertStatus(200);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/iot/unlock', ['device_id' => $lock->id, 'key_token' => $plainKey])
            ->assertStatus(403);

        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/iot/keys', ['room_or_table_label' => 'Suite 101', 'reservation_id' => $res->id])
            ->assertStatus(403);

        // #119 Stolen device: revoke-all deletes every token for user; old tokens return 401
        $tok1 = $customer->createToken('tablet-1')->plainTextToken;
        $tok2 = $customer->createToken('phone-2')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($tok1)->postJson('/api/auth/revoke-all')->assertStatus(200);
        $this->app['auth']->forgetGuards();
        $this->withToken($tok1)->getJson('/api/auth/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($tok2)->getJson('/api/auth/me')->assertStatus(401);

        // #123 CEO Fraud Dual-Approval: Single admin cannot change payout IBAN alone (self-approval returns 403)
        $admin2 = User::create([
            'name' => 'Second Admin',
            'email' => 'admin2_' . uniqid() . '@alpha.test',
            'password' => Hash::make('StrongPass123'),
            'current_tenant_id' => $ctx['tenantA']->id,
        ]);
        $ctx['tenantA']->users()->attach($admin2->id, ['role' => 'admin']);

        $payoutReq = $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson('/api/security/approvals/payout-change', [
                'payout_iban' => 'IT60X0542811101000000123456',
                'payout_account_name' => 'Hotel Alpha Treasury',
            ])->assertStatus(202);
        $approvalId = $payoutReq->json('approval_id');

        // Same admin trying to self-approve is strictly blocked (403)
        $this->actingAs($ctx['adminA'], 'sanctum')
            ->postJson("/api/security/approvals/{$approvalId}/approve")
            ->assertStatus(403);

        // Second admin approves -> executed cleanly
        $this->actingAs($admin2, 'sanctum')
            ->postJson("/api/security/approvals/{$approvalId}/approve")
            ->assertStatus(200);
        $this->assertEquals('IT60X0542811101000000123456', $ctx['tenantA']->fresh()->payout_iban);
    }

    /**
     * Item 1: Verify PostgreSQL Row Level Security (RLS) role separation, FORCE RLS,
     * fail-closed behavior when app.current_tenant_id is unset, and strict cross-tenant block
     * for a restricted non-superuser runtime role executing raw SQL.
     */
    public function test_postgres_rls_enforces_fail_closed_and_blocks_cross_tenant_for_runtime_role(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL RLS role-separation test requires pgsql driver.');
        }

        $ctx = $this->seedTwoTenants();

        $tableA = RestaurantTable::create([
            'tenant_id' => $ctx['tenantA']->id,
            'table_number' => 'RLS-A1',
            'qr_code_token' => 'qr_rls_a1_' . uniqid(),
            'is_active' => true,
        ]);
        $tableB = RestaurantTable::create([
            'tenant_id' => $ctx['tenantB']->id,
            'table_number' => 'RLS-B1',
            'qr_code_token' => 'qr_rls_b1_' . uniqid(),
            'is_active' => true,
        ]);

        // Commit seeded tenants & tables so separate runtime PDO connection sees foreign keys
        DB::commit();

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $db = config('database.connections.pgsql.database');
        $runtimeUser = 'cafex_runtime_test';
        $runtimePass = 'cafex_runtime_test_secret_pass';

        // Connect using the restricted non-owner, non-superuser runtime role (NOSUPERUSER, NOBYPASSRLS)
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        $pdo = new \PDO($dsn, $runtimeUser, $runtimePass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->beginTransaction();
        try {
            // Seed test orders directly through the runtime connection for Tenant A and Tenant B
            $pdo->exec("SET LOCAL app.current_tenant_id = '{$ctx['tenantA']->id}';");
            $pdo->exec("
                INSERT INTO orders (tenant_id, table_id, order_number, total, status, created_at, updated_at)
                VALUES ({$ctx['tenantA']->id}, {$tableA->id}, 'ORD-RLS-A1', 45.00, 'pending', NOW(), NOW());
            ");

            $pdo->exec("SET LOCAL app.current_tenant_id = '{$ctx['tenantB']->id}';");
            $pdo->exec("
                INSERT INTO orders (tenant_id, table_id, order_number, total, status, created_at, updated_at)
                VALUES ({$ctx['tenantB']->id}, {$tableB->id}, 'ORD-RLS-B1', 88.00, 'pending', NOW(), NOW());
            ");

            // 1. Fail-closed: With NO tenant set in session, raw SELECT returns ZERO rows
            $pdo->exec("SET LOCAL app.current_tenant_id = '';");
            $stmt = $pdo->query("SELECT * FROM orders;");
            $rowsNoTenant = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(0, $rowsNoTenant, "RLS must fail-closed and return 0 rows when app.current_tenant_id is empty");

            // 2. Set tenant to A: Raw SELECT returns ONLY Tenant A rows, zero from Tenant B
            $pdo->exec("SET LOCAL app.current_tenant_id = '{$ctx['tenantA']->id}';");
            $stmt = $pdo->query("SELECT * FROM orders;");
            $rowsTenantA = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertNotEmpty($rowsTenantA);

            $returnedNumbers = array_column($rowsTenantA, 'order_number');
            $this->assertContains('ORD-RLS-A1', $returnedNumbers);
            $this->assertNotContains('ORD-RLS-B1', $returnedNumbers);

            foreach ($rowsTenantA as $r) {
                $this->assertEquals((int) $ctx['tenantA']->id, (int) $r['tenant_id']);
                $this->assertNotEquals((int) $ctx['tenantB']->id, (int) $r['tenant_id']);
            }

            // 3. Raw bypass attempt with explicit WHERE tenant_id = B returns zero rows
            $stmt = $pdo->query("SELECT * FROM orders WHERE tenant_id = {$ctx['tenantB']->id};");
            $bRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(0, $bRows, "Raw query attempting cross-tenant access must return zero rows under RLS");

            // 4. Cross-tenant write: Inserting row for Tenant B while locked to Tenant A fails with RLS policy violation
            $threw = false;
            try {
                $pdo->exec("
                    INSERT INTO orders (tenant_id, table_id, order_number, total, status, created_at, updated_at)
                    VALUES ({$ctx['tenantB']->id}, {$tableB->id}, 'TAMPER-ORDER', 99, 'pending', NOW(), NOW())
                ");
            } catch (\PDOException $e) {
                $threw = true;
                $this->assertStringContainsString('violates row-level security policy', $e->getMessage());
            }
            $this->assertTrue($threw, "Cross-tenant raw INSERT must trigger RLS WITH CHECK policy violation");

            $pdo->rollBack();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            DB::beginTransaction();
        }
    }
}
