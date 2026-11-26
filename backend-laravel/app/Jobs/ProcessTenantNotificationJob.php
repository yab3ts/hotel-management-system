<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTenantNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $recipientUserId,
        public string $event,
        public array $data = []
    ) {
    }

    /**
     * Execute job inside strict tenant context (M17 & M19).
     */
    public function handle(): void
    {
        $tenant = Tenant::where('is_active', true)->find($this->tenantId);
        if (!$tenant) {
            return;
        }

        TenantContext::setTenant($tenant);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, false)", [(string) $tenant->id]);
        }

        try {
            $recipient = User::find($this->recipientUserId);
            // M17: Prevent cross-tenant notification leakage if recipient does not belong to job's tenant
            if (!$recipient || !$recipient->tenants()->where('tenants.id', $tenant->id)->exists()) {
                Log::warning('Blocked cross-tenant queued notification', [
                    'tenant_id' => $tenant->id,
                    'recipient_user_id' => $this->recipientUserId,
                ]);
                return;
            }

            AuditLog::record('tenant_notification_dispatched', $recipient, [
                'tenant_id' => $tenant->id,
                'event' => $this->event,
                'recipient_email' => $recipient->email,
            ], $recipient->id);
        } finally {
            TenantContext::clear();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.current_tenant_id', '', false)");
            }
        }
    }
}
