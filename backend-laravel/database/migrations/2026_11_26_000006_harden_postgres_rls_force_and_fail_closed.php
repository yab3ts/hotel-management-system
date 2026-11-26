<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        'iot_devices',
        'digital_keys',
        'approval_requests',
    ];

    /**
     * Run the migrations.
     * Enforce strict fail-closed Row Level Security with FORCE ROW LEVEL SECURITY (M5).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Provision restricted test runtime role for test assertions
            DB::statement("
                DO $$
                BEGIN
                    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'cafex_runtime_test') THEN
                        CREATE ROLE cafex_runtime_test WITH LOGIN PASSWORD 'cafex_runtime_test_secret_pass' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
                    END IF;
                END
                $$;
            ");

            foreach ($this->rlsTables as $table) {
                // FORCE RLS ensures table owners and non-superusers are strictly bound by policies
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
                // Fail-closed: returns zero rows when app.current_tenant_id is not set, unless app.bypass_rls = 'on'
                DB::statement("
                    CREATE POLICY tenant_isolation_policy ON {$table}
                    USING (
                        (
                            tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
                        )
                        OR current_setting('app.bypass_rls', true) = 'on'
                    )
                    WITH CHECK (
                        (
                            tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
                        )
                        OR current_setting('app.bypass_rls', true) = 'on'
                    )
                ");

                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO cafex_runtime_test;");
            }

            DB::statement("GRANT USAGE ON SCHEMA public TO cafex_runtime_test;");
            DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO cafex_runtime_test;");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->rlsTables as $table) {
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }

            DB::statement("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM cafex_runtime_test;");
            DB::statement("REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM cafex_runtime_test;");
            DB::statement("REVOKE ALL PRIVILEGES ON SCHEMA public FROM cafex_runtime_test;");
            DB::statement("DROP OWNED BY cafex_runtime_test;");
            DB::statement("DROP ROLE IF EXISTS cafex_runtime_test;");
        }
    }
};
