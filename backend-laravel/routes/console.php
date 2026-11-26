<?php

use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// #59 Reservation Hold Auto-Expiry: cancels pending reservations older than 30 mins
Artisan::command('reservations:expire-holds {--minutes=30}', function () {
    $cutoff = now()->subMinutes((int) $this->option('minutes'));
    $expired = Reservation::withoutGlobalScopes()
        ->where('status', 'pending')
        ->where('created_at', '<', $cutoff)
        ->update(['status' => 'cancelled']);
    $this->info("Expired {$expired} stale pending reservation holds.");
})->purpose('Auto-expire unconfirmed reservation holds older than N minutes');

// #83 Retention Purge: removes old security audit logs beyond 90-day policy
Artisan::command('privacy:purge-retention {--days=90}', function () {
    $cutoff = now()->subDays((int) $this->option('days'));
    // Direct table purge bypasses immutable Eloquent delete hook for scheduled compliance retention only
    $deleted = DB::table('audit_logs')->where('created_at', '<', $cutoff)->delete();
    $this->info("Purged {$deleted} audit logs older than {$this->option('days')} days.");
})->purpose('Enforce 90-day privacy audit log retention policy');

// M23 Per-Tenant Isolated Export (for single-tenant backup without exposing others)
Artisan::command('tenant:export {tenant_id} {--path=}', function () {
    $tenantId = (int) $this->argument('tenant_id');
    $tenant = Tenant::findOrFail($tenantId);
    $dir = $this->option('path') ?: storage_path('app/backups/tenants');
    if (!File::exists($dir)) {
        File::makeDirectory($dir, 0750, true);
    }

    $payload = [
        'exported_at' => now()->toIso8601String(),
        'tenant' => $tenant->toArray(),
        'branches' => DB::table('branches')->where('tenant_id', $tenantId)->get(),
        'tables' => DB::table('restaurant_tables')->where('tenant_id', $tenantId)->get(),
        'menu_categories' => DB::table('menu_categories')->where('tenant_id', $tenantId)->get(),
        'menu_items' => DB::table('menu_items')->where('tenant_id', $tenantId)->get(),
        'reservations' => DB::table('reservations')->where('tenant_id', $tenantId)->get(),
        'orders' => DB::table('orders')->where('tenant_id', $tenantId)->get(),
        'payments' => DB::table('payments')->where('tenant_id', $tenantId)->get(),
    ];

    $file = "{$dir}/tenant_{$tenantId}_export_" . date('Ymd_His') . '.json';
    File::put($file, json_encode($payload, JSON_PRETTY_PRINT));
    $this->info("Exported isolated tenant #{$tenantId} to {$file}");
})->purpose('Export isolated snapshot for a single tenant');

// M15 Tenant Offboarding Purge (deletes a single tenant cleanly without affecting others)
Artisan::command('tenant:purge {tenant_id} {--force}', function () {
    $tenantId = (int) $this->argument('tenant_id');
    $tenant = Tenant::findOrFail($tenantId);
    if (!$this->option('force') && !app()->runningUnitTests()) {
        if (!$this->confirm("Permanently purge tenant #{$tenantId} ({$tenant->name}) and all its scoped data?", false)) {
            return;
        }
    }

    DB::transaction(function () use ($tenantId, $tenant) {
        foreach (['payments', 'orders', 'table_sessions', 'reservations', 'inventory', 'menu_items', 'menu_categories', 'restaurant_tables', 'branches', 'audit_logs'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
        $tenant->users()->detach();
        $tenant->delete();
    });

    $this->info("Tenant #{$tenantId} purged cleanly.");
})->purpose('Offboard and purge a single tenant without impacting other tenants');

Schedule::command('reservations:expire-holds')->everyFifteenMinutes();
Schedule::command('privacy:purge-retention')->daily();
Schedule::command('cafex:backup')->dailyAt('02:00');
