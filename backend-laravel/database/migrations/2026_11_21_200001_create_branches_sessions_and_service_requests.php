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
        // 1. Branches table (Multi-branch under Tenant)
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('slug');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('tax_rate', 5, 2)->default(10.00);
            $table->decimal('service_charge', 5, 2)->default(5.00);
            $table->time('opening_time')->default('07:00:00');
            $table->time('closing_time')->default('23:00:00');
            $table->boolean('is_qr_ordering_enabled')->default(true);
            $table->integer('session_timeout_minutes')->default(180);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // 2. Add branch_id to restaurant_tables
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained('branches')->nullOnDelete();
        });

        // 3. Table Sessions (Shared running tabs for QR table dining)
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->string('session_token')->unique();
            $table->string('status')->default('active'); // active, billing, closed, expired
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('service_charge', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->boolean('manager_override_settle')->default(false);
            $table->text('settle_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 4. Session Guests (Co-diners on the same table tab)
        Schema::create('session_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('table_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name');
            $table->string('guest_phone')->nullable();
            $table->string('guest_secret')->unique()->nullable();
            $table->string('device_fingerprint')->nullable();
            $table->timestamp('joined_at')->useCurrent();
        });

        // 5. Service Requests (Call Waiter / Request Bill)
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('table_sessions')->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('session_guests')->nullOnDelete();
            $table->string('type'); // call_waiter, request_bill, water, cutlery
            $table->string('status')->default('pending'); // pending, acknowledged, resolved
            $table->foreignId('assigned_waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // 6. Link orders to table session, branch, guest, prep timestamps and idempotency
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->after('table_id')->constrained('table_sessions')->nullOnDelete();
            $table->string('guest_name')->default('Guest')->after('user_id');
            $table->string('idempotency_key')->nullable()->index()->after('order_number');
            $table->decimal('service_charge', 10, 2)->default(0)->after('tax');
            $table->string('payment_choice')->default('pay_later')->after('total');
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });

        // 7. Audit Logs Table for Security, Overrides, & Price Integrity
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['guest_name', 'service_charge', 'payment_choice']);
        });
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('session_guests');
        Schema::dropIfExists('table_sessions');
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::dropIfExists('branches');
    }
};
