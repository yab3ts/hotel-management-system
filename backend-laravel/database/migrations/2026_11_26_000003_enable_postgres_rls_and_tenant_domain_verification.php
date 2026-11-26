<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $rlsTables = [
        'orders',
        'payments',
        'table_sessions',
        'reservations',
        'audit_logs',
        'inventory',
        'restaurant_tables',
        'menu_items',
    ];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'domain_verification_token')) {
                $table->string('domain_verification_token')->nullable()->after('domain');
            }
            if (!Schema::hasColumn('tenants', 'domain_verified_at')) {
                $table->timestamp('domain_verified_at')->nullable()->after('domain_verification_token');
            }
        });

        // Defense-in-depth PostgreSQL Row-Level Security (M5)
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->rlsTables as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
                DB::statement("
                    CREATE POLICY tenant_isolation_policy ON {$table}
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
            foreach ($this->rlsTables as $table) {
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['domain_verification_token', 'domain_verified_at']);
        });
    }
};
