<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\SecurityAlert;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SecurityOperationsController extends Controller
{
    /**
     * List security & anomaly alerts for the active tenant (#133).
     */
    public function alerts(Request $request): JsonResponse
    {
        $alerts = SecurityAlert::latest()->take(100)->get();
        return response()->json([
            'alerts' => $alerts,
            'unresolved_count' => $alerts->whereNull('resolved_at')->count(),
        ]);
    }

    /**
     * Resolve a security alert (#133).
     */
    public function resolveAlert(Request $request, SecurityAlert $alert): JsonResponse
    {
        $alert->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
        ]);

        AuditLog::record('security_alert_resolved', $alert, ['alert_id' => $alert->id]);

        return response()->json([
            'message' => 'Security alert marked as resolved.',
            'alert' => $alert,
        ]);
    }

    /**
     * Admin-only guest reservation export with bulk export anomaly alerting (#86, #124).
     */
    public function exportGuests(Request $request): JsonResponse
    {
        $user = $request->user();
        $threshold = (int) $request->query('alert_threshold', 10);

        $rows = Reservation::orderByDesc('created_at')
            ->get(['id', 'reservation_number', 'guest_name', 'guest_email', 'guest_count', 'reservation_date', 'status', 'is_vip']);

        if ($rows->count() >= $threshold) {
            SecurityAlert::raise(
                'bulk_data_export',
                "Bulk guest export triggered by {$user?->email} ({$rows->count()} records).",
                'high',
                $user?->id,
                ['exported_count' => $rows->count(), 'threshold' => $threshold]
            );
        }

        AuditLog::record('guest_data_exported', null, [
            'exported_count' => $rows->count(),
        ], $user?->id);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'count' => $rows->count(),
            'records' => $rows,
        ]);
    }

    /**
     * System resilience, DB failover health, and incident response readiness (#129, #131, #132).
     */
    public function readiness(): JsonResponse
    {
        $dbHealthy = false;
        $driver = DB::getDriverName();
        $rlsEnabledTables = 0;

        try {
            DB::select('SELECT 1');
            $dbHealthy = true;
            if ($driver === 'pgsql') {
                $rlsEnabledTables = (int) DB::table('pg_class')
                    ->join('pg_namespace', 'pg_class.relnamespace', '=', 'pg_namespace.oid')
                    ->where('pg_namespace.nspname', 'public')
                    ->where('pg_class.relrowsecurity', true)
                    ->count();
            }
        } catch (\Throwable) {
            $dbHealthy = false;
        }

        $irPlanExists = File::exists(base_path('docs/INCIDENT_RESPONSE_PLAN.md'));

        return response()->json([
            'database' => [
                'healthy' => $dbHealthy,
                'driver' => $driver,
                'rls_protected_tables' => $rlsEnabledTables,
            ],
            'backup_encryption' => 'aes-256-gcm',
            'incident_response_plan' => $irPlanExists,
            'wildcard_domains' => config('app.wildcard_domains'),
            'mfa_admin_enforced' => (bool) config('app.enforce_admin_mfa'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Secure idempotent offline sync queue for internet outages (#134).
     * Recomputes prices authoritatively server-side and rejects tampered/stale items.
     */
    public function syncOfflineQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'orders' => ['required', 'array', 'min:1', 'max:50'],
            'orders.*.idempotency_key' => ['required', 'string', 'max:120'],
            'orders.*.queued_at' => ['required', 'date', 'after:' . now()->subHours(24)->toIso8601String()],
            'orders.*.table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'orders.*.guest_name' => ['nullable', 'string', 'max:100'],
            'orders.*.items' => ['required', 'array', 'min:1'],
            'orders.*.items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'orders.*.items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $tenant = TenantContext::getTenant();
        $synced = [];
        $skipped = [];

        DB::transaction(function () use ($validated, $tenant, $request, &$synced, &$skipped) {
            foreach ($validated['orders'] as $offlineOrder) {
                $existing = Order::where('idempotency_key', $offlineOrder['idempotency_key'])->first();
                if ($existing) {
                    $skipped[] = [
                        'idempotency_key' => $offlineOrder['idempotency_key'],
                        'order_id' => $existing->id,
                        'reason' => 'already_synced',
                    ];
                    continue;
                }

                $subtotal = 0;
                $preparedItems = [];
                foreach ($offlineOrder['items'] as $itemInput) {
                    $menuItem = MenuItem::findOrFail($itemInput['menu_item_id']);
                    $qty = (int) $itemInput['quantity'];
                    $unitPrice = (float) $menuItem->price; // Always ignore offline client price
                    $lineTotal = round($unitPrice * $qty, 2);
                    $subtotal += $lineTotal;
                    $preparedItems[] = [
                        'menu_item_id' => $menuItem->id,
                        'item_name' => $menuItem->name,
                        'unit_price' => $unitPrice,
                        'quantity' => $qty,
                        'subtotal' => $lineTotal,
                        'status' => 'placed',
                    ];
                }

                $tax = round($subtotal * 0.10, 2);
                $total = round($subtotal + $tax, 2);
                $table = !empty($offlineOrder['table_id'])
                    ? RestaurantTable::find($offlineOrder['table_id'])
                    : null;

                $order = Order::create([
                    'tenant_id' => $tenant?->id,
                    'branch_id' => $table?->branch_id,
                    'user_id' => $request->user()?->id,
                    'table_id' => $table?->id,
                    'guest_name' => strip_tags($offlineOrder['guest_name'] ?? 'Offline Sync Guest'),
                    'order_number' => '#OFF-' . strtoupper(Str::random(5)),
                    'order_type' => $table ? 'dine_in' : 'takeaway',
                    'status' => 'placed',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'idempotency_key' => $offlineOrder['idempotency_key'],
                    'placed_at' => now(),
                ]);

                foreach ($preparedItems as $pItem) {
                    $order->items()->create($pItem);
                }

                $synced[] = [
                    'idempotency_key' => $offlineOrder['idempotency_key'],
                    'order_id' => $order->id,
                    'recomputed_total' => $total,
                ];
            }
        });

        AuditLog::record('offline_sync_completed', null, [
            'device_id' => strip_tags($validated['device_id']),
            'synced_count' => count($synced),
            'skipped_count' => count($skipped),
        ], $request->user()?->id);

        return response()->json([
            'message' => 'Offline queue synchronized safely.',
            'synced' => $synced,
            'skipped' => $skipped,
        ]);
    }
}
