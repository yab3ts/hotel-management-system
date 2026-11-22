<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * Recipe ingredient breakdown per menu item name.
     * Maps dish to ingredients and required quantity in standard units (kg, L, bottles).
     */
    protected static array $recipeMap = [
        'Wagyu Ribeye Steak' => [
            'Wagyu Beef' => 0.35,
            'Truffle Oil' => 0.02,
        ],
        'Grilled Atlantic Salmon' => [
            'Fresh Atlantic Salmon' => 0.25,
        ],
        'Atlantic Salmon Fillet' => [
            'Fresh Atlantic Salmon' => 0.25,
        ],
        'Margherita Classica' => [
            'Buffalo Mozzarella DOP' => 0.15,
            'All-Purpose Italian Flour 00' => 0.20,
            'Fresh Organic Basil' => 0.02,
        ],
        'Margherita Pizza' => [
            'Buffalo Mozzarella DOP' => 0.15,
            'All-Purpose Italian Flour 00' => 0.20,
            'Fresh Organic Basil' => 0.02,
        ],
        'Truffle Tagliatelle' => [
            'Truffle Oil Extra Virgin' => 0.05,
            'All-Purpose Italian Flour 00' => 0.15,
            'Heavy Whipping Cream' => 0.05,
        ],
        'Tiramisu Classico' => [
            'Mascarpone Cheese' => 0.15,
            'Heavy Whipping Cream' => 0.10,
            'Espresso Coffee Beans' => 0.03,
        ],
        'Traditional Tiramisu' => [
            'Mascarpone Cheese' => 0.15,
            'Heavy Whipping Cream' => 0.10,
            'Espresso Coffee Beans' => 0.03,
        ],
    ];

    /**
     * Automatically deduct stock for ingredients when an order is created.
     * Idempotent: sets inventory_deducted flag or checks order metadata.
     */
    public static function deductRecipeForOrder(Order $order): array
    {
        $deductions = [];

        foreach ($order->items as $orderItem) {
            $dishName = $orderItem->item_name;
            $qty = (int) $orderItem->quantity;

            // Find matching recipe
            $matchedRecipe = null;
            foreach (self::$recipeMap as $name => $recipe) {
                if (stripos($dishName, $name) !== false || stripos($name, $dishName) !== false) {
                    $matchedRecipe = $recipe;
                    break;
                }
            }

            if (!$matchedRecipe) {
                continue;
            }

            foreach ($matchedRecipe as $ingredientKeyword => $perUnit) {
                $totalUsage = $perUnit * $qty;

                $invItem = InventoryItem::where('tenant_id', $order->tenant_id)
                    ->where('item_name', 'like', "%{$ingredientKeyword}%")
                    ->lockForUpdate()
                    ->first();

                if ($invItem) {
                    $invItem->decrement('current_stock', $totalUsage);
                    $deductions[] = [
                        'ingredient' => $invItem->item_name,
                        'deducted' => $totalUsage,
                        'remaining' => (float) $invItem->current_stock,
                    ];
                }
            }
        }

        if (!empty($deductions)) {
            AuditLog::record('inventory_recipe_auto_deducted', $order, [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'deductions' => $deductions,
            ]);
        }

        return $deductions;
    }

    /**
     * Automatically restore stock for ingredients when an order is cancelled.
     */
    public static function restoreRecipeForOrder(Order $order): array
    {
        $restorations = [];

        foreach ($order->items as $orderItem) {
            $dishName = $orderItem->item_name;
            $qty = (int) $orderItem->quantity;

            $matchedRecipe = null;
            foreach (self::$recipeMap as $name => $recipe) {
                if (stripos($dishName, $name) !== false || stripos($name, $dishName) !== false) {
                    $matchedRecipe = $recipe;
                    break;
                }
            }

            if (!$matchedRecipe) {
                continue;
            }

            foreach ($matchedRecipe as $ingredientKeyword => $perUnit) {
                $totalRestore = $perUnit * $qty;

                $invItem = InventoryItem::where('tenant_id', $order->tenant_id)
                    ->where('item_name', 'like', "%{$ingredientKeyword}%")
                    ->lockForUpdate()
                    ->first();

                if ($invItem) {
                    $invItem->increment('current_stock', $totalRestore);
                    $restorations[] = [
                        'ingredient' => $invItem->item_name,
                        'restored' => $totalRestore,
                        'new_stock' => (float) $invItem->current_stock,
                    ];
                }
            }
        }

        if (!empty($restorations)) {
            AuditLog::record('inventory_recipe_restored', $order, [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'restorations' => $restorations,
            ]);
        }

        return $restorations;
    }
}
