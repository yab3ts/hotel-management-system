<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('mfa_recovery_codes');
            }
            if (!Schema::hasColumn('users', 'is_vip')) {
                $table->boolean('is_vip')->default(false)->after('is_super_admin');
            }
            if (!Schema::hasColumn('users', 'impersonation_consent_token')) {
                $table->string('impersonation_consent_token', 128)->nullable()->after('is_vip');
            }
            if (!Schema::hasColumn('users', 'impersonation_consented_until')) {
                $table->timestamp('impersonation_consented_until')->nullable()->after('impersonation_consent_token');
            }
        });

        Schema::table('reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('reservations', 'is_vip')) {
                $table->boolean('is_vip')->default(false)->after('status');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'encryption_key_salt')) {
                $table->string('encryption_key_salt', 64)->nullable()->after('domain_verified_at');
            }
            if (!Schema::hasColumn('tenants', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable()->after('encryption_key_salt');
            }
        });

        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severity', 20)->default('medium'); // low, medium, high, critical
            $table->string('category', 80); // after_hours_login, bulk_data_export, vip_record_accessed, brute_force, impersonation_started, cross_tenant_attempt
            $table->text('description');
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE security_alerts ENABLE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON security_alerts');
            DB::statement("
                CREATE POLICY tenant_isolation_policy ON security_alerts
                USING (
                    NULLIF(current_setting('app.current_tenant_id', true), '') IS NULL
                    OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
                )
                WITH CHECK (
                    NULLIF(current_setting('app.current_tenant_id', true), '') IS NULL
                    OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON security_alerts');
            DB::statement('ALTER TABLE security_alerts DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('security_alerts');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['encryption_key_salt', 'webhook_secret']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('is_vip');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_super_admin',
                'is_vip',
                'impersonation_consent_token',
                'impersonation_consented_until',
            ]);
        });
    }
};
