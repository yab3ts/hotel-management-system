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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('table_sessions')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('session_guests')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('payment_method')->default('card'); // card, cash, mobile_money, online, coins
            $table->string('payment_type')->default('full'); // full, partial, split_equal, split_item
            $table->string('status')->default('completed'); // pending, completed, failed, refunded
            $table->string('transaction_reference')->unique()->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Add payload_hash to orders table for robust idempotency verification
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payload_hash');
        });
        Schema::dropIfExists('payments');
    }
};
