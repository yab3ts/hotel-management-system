<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('table_number');
            $table->string('name')->nullable();
            $table->integer('capacity')->default(2);
            $table->string('zone')->default('Main'); // Window, Patio, VIP, Main, Private
            $table->string('status')->default('available'); // available, occupied, reserved, maintenance
            $table->string('qr_token')->unique()->nullable();
            $table->integer('qr_version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'table_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
