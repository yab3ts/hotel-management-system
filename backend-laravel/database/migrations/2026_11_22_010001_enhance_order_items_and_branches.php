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
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('status')->constrained('payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('payment_id');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('max_order_amount', 10, 2)->default(1000.00)->after('is_qr_ordering_enabled');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->string('table_pin', 6)->nullable()->after('qr_version');
            $table->boolean('requires_pin_to_order')->default(false)->after('table_pin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn(['table_pin', 'requires_pin_to_order']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('max_order_amount');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn('paid_at');
        });
    }
};
