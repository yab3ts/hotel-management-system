<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enforces the architectural invariant: strictly one open (active or billing) session per table.
     */
    public function up(): void
    {
        // Partial unique index supported on both PostgreSQL and SQLite (>= 3.8.0)
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS unique_open_table_session ON table_sessions (table_id) WHERE status IN ('active', 'billing');");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS unique_open_table_session;");
    }
};
