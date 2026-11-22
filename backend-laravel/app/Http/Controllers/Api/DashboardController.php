<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Get aggregate statistics for active tenant dashboard.
     */
    public function stats(): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        $totalOrders = Order::count();
        // Telemetry authoritatively reconciled against the immutable Payment ledger
        $grossRevenue = (float) Payment::where('status', 'completed')->where('amount', '>', 0)->sum('amount');
        $refundsTotal = abs((float) Payment::where('status', 'completed')->where('amount', '<', 0)->sum('amount'));
        $netRevenue = $grossRevenue - $refundsTotal;

        $totalTables = RestaurantTable::count();
        $availableTables = RestaurantTable::where('status', 'available')->count();
        $occupiedTables = RestaurantTable::where('status', 'occupied')->count();

        $lowStockCount = InventoryItem::whereColumn('current_stock', '<=', 'minimum_stock')->count();

        $recentOrders = Order::with(['items', 'table'])
            ->latest()
            ->take(6)
            ->get();

        $popularDishes = MenuItem::where('is_featured', true)
            ->orWhere('is_available', true)
            ->take(4)
            ->get();

        return response()->json([
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'currency' => $tenant->currency,
            ] : null,
            'stats' => [
                'total_orders' => $totalOrders,
                'revenue_today' => '$' . number_format($netRevenue, 2),
                'net_revenue' => $netRevenue,
                'gross_revenue' => $grossRevenue,
                'refunds_total' => $refundsTotal,
                'available_tables' => "{$availableTables}/{$totalTables}",
                'occupied_tables' => $occupiedTables,
                'low_stock_alerts' => $lowStockCount,
            ],
            'recent_orders' => $recentOrders,
            'popular_dishes' => $popularDishes,
        ]);
    }

    /**
     * Kitchen Display System (KDS) performance queue.
     */
    public function kitchenStats(): JsonResponse
    {
        $activeOrders = Order::with(['items', 'table'])
            ->whereIn('status', ['pending', 'confirmed', 'preparing'])
            ->orderBy('created_at', 'asc')
            ->get();

        $readyOrders = Order::with(['items', 'table'])
            ->where('status', 'ready')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'active_queue_count' => $activeOrders->count(),
            'active_orders' => $activeOrders,
            'ready_orders' => $readyOrders,
        ]);
    }
}
