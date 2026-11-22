<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryItem as Inventory;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * List all inventory items with critical/low stock metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID') ?? 1;

        $items = Inventory::where('tenant_id', $tenantId)->get()->map(function ($item) {
            $status = 'ok';
            if ($item->current_stock <= 0 || $item->current_stock <= ($item->minimum_stock * 0.5)) {
                $status = 'critical';
            } elseif ($item->current_stock <= $item->minimum_stock) {
                $status = 'low';
            }

            return [
                'id' => $item->id,
                'name' => $item->item_name,
                'category' => $item->category,
                'stock' => (float) $item->current_stock,
                'threshold' => (float) $item->minimum_stock,
                'unit' => $item->unit,
                'cost_per_unit' => (float) $item->cost_per_unit,
                'supplier' => $item->supplier,
                'status' => $status,
                'updated_at' => $item->updated_at->toIso8601String(),
            ];
        });

        $criticalCount = $items->where('status', 'critical')->count();
        $lowCount = $items->where('status', 'low')->count();

        return response()->json([
            'items' => $items,
            'stats' => [
                'total_items' => $items->count(),
                'critical_items' => $criticalCount,
                'low_stock_items' => $lowCount,
            ],
        ]);
    }

    /**
     * Create new inventory item.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'current_stock' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:20'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = $request->header('X-Tenant-ID') ?? 1;

        $item = Inventory::create([
            'tenant_id' => $tenantId,
            'item_name' => $validated['item_name'],
            'category' => $validated['category'] ?? 'General',
            'current_stock' => $validated['current_stock'],
            'minimum_stock' => $validated['minimum_stock'],
            'unit' => $validated['unit'],
            'cost_per_unit' => $validated['cost_per_unit'] ?? 0,
            'supplier' => $validated['supplier'] ?? 'Direct Supplier',
        ]);

        AuditLog::record('inventory_item_created', $item, [
            'item_id' => $item->id,
            'name' => $item->item_name,
            'stock' => $item->current_stock,
        ]);

        return response()->json([
            'message' => 'Inventory item created successfully.',
            'item' => $item,
        ], 201);
    }

    /**
     * Update or restock an inventory item.
     */
    public function update(Request $request, Inventory $inventory): JsonResponse
    {
        $validated = $request->validate([
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'stock_delta' => ['nullable', 'numeric'], // e.g. +10 (restock) or -2 (spoilage/usage)
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated, $inventory) {
            $oldStock = (float) $inventory->current_stock;
            $newStock = $oldStock;

            if (isset($validated['current_stock'])) {
                $newStock = (float) $validated['current_stock'];
            } elseif (isset($validated['stock_delta'])) {
                $newStock = max(0, $oldStock + (float) $validated['stock_delta']);
            }

            $inventory->current_stock = $newStock;
            if (isset($validated['minimum_stock'])) {
                $inventory->minimum_stock = (float) $validated['minimum_stock'];
            }
            $inventory->save();

            AuditLog::record('inventory_stock_adjusted', $inventory, [
                'item_id' => $inventory->id,
                'name' => $inventory->item_name,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'notes' => $validated['notes'] ?? 'Manual stock adjustment',
            ]);

            return response()->json([
                'message' => "Stock updated for {$inventory->item_name}.",
                'inventory' => $inventory,
            ]);
        });
    }

    /**
     * Auto-deduct inventory for recipe ingredients when a dish is prepared.
     */
    public function deductRecipe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dish_name' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $tenantId = $request->header('X-Tenant-ID') ?? 1;

        // Common ingredient mapping for Café X signature dishes
        $recipeMap = [
            'Wagyu Ribeye Steak' => ['Wagyu Beef' => 0.35],
            'Atlantic Salmon Fillet' => ['Fresh Salmon' => 0.25],
            'Margherita Pizza' => ['Mozzarella' => 0.15, 'All-Purpose Flour' => 0.20, 'Fresh Basil' => 0.02],
            'Truffle Tagliatelle' => ['Truffle Oil' => 0.05, 'All-Purpose Flour' => 0.15, 'Heavy Cream' => 0.05],
            'Tiramisu Classico' => ['Heavy Cream' => 0.10],
        ];

        $dish = $validated['dish_name'];
        $qty = $validated['quantity'];

        $deducted = [];
        if (isset($recipeMap[$dish])) {
            foreach ($recipeMap[$dish] as $ingredient => $perUnitUsage) {
                $totalUsage = $perUnitUsage * $qty;
                $invItem = Inventory::where('tenant_id', $tenantId)
                    ->where('item_name', 'like', "%{$ingredient}%")
                    ->first();

                if ($invItem) {
                    $invItem->decrement('current_stock', $totalUsage);
                    $deducted[] = [
                        'ingredient' => $invItem->item_name,
                        'deducted' => $totalUsage,
                        'remaining' => (float) $invItem->current_stock,
                    ];
                }
            }
        }

        return response()->json([
            'message' => "Recipe stock auto-deducted for {$qty}x {$dish}.",
            'deductions' => $deducted,
        ]);
    }
}
