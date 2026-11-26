<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'payout_iban')) {
                $table->text('payout_iban')->nullable()->after('webhook_secret');
            }
            if (!Schema::hasColumn('tenants', 'payout_account_name')) {
                $table->string('payout_account_name')->nullable()->after('payout_iban');
            }
        });

        Schema::create('iot_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('device_type', 40); // door_lock, tv, thermostat, camera, pos_terminal
            $table->string('device_identifier');
            $table->string('credential_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_identifier']);
        });

        Schema::create('digital_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('table_sessions')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->cascadeOnDelete();
            $table->string('room_or_table_label', 60);
            $table->string('key_token_hash', 128);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60); // payout_bank_change, high_value_refund
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, executed
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['iot_devices', 'digital_keys', 'approval_requests'] as $tbl) {
                DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$tbl}");
                DB::statement("
                    CREATE POLICY tenant_isolation_policy ON {$tbl}
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
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['iot_devices', 'digital_keys', 'approval_requests'] as $tbl) {
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$tbl}");
                DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
            }
        }

        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('digital_keys');
        Schema::dropIfExists('iot_devices');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['payout_iban', 'payout_account_name']);
        });
    }
};
