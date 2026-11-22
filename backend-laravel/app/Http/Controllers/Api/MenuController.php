<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    /**
     * List all menu items for the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::with('category');

        if ($categorySlug = $request->query('category')) {
            if ($categorySlug !== 'All') {
                $query->whereHas('category', function ($q) use ($categorySlug) {
                    $q->where('slug', $categorySlug)->orWhere('name', $categorySlug);
                });
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('available_only')) {
            $query->where('is_available', true);
        }

        $items = $query->orderBy('name')->get();

        return response()->json($items);
    }

    /**
     * List categories for current tenant.
     */
    public function categories(): JsonResponse
    {
        $categories = MenuCategory::where('is_active', true)
            ->withCount('items')
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories);
    }

    /**
     * Create menu item.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('category') && !$request->has('category_id')) {
            $cat = MenuCategory::firstOrCreate(
                ['name' => $request->input('category')],
                ['slug' => Str::slug($request->input('category')), 'sort_order' => 10]
            );
            $request->merge(['category_id' => $cat->id]);
        } elseif (!$request->has('category_id')) {
            $firstCat = MenuCategory::first();
            if ($firstCat) {
                $request->merge(['category_id' => $firstCat->id]);
            }
        }

        $validated = $request->validate([
            'category_id' => ['required', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1'],
            'is_available' => ['nullable', 'boolean'],
            'is_spicy' => ['nullable', 'boolean'],
            'is_vegetarian' => ['nullable', 'boolean'],
            'is_vegan' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'calories' => ['nullable', 'integer'],
        ]);

        $item = MenuItem::create($validated);

        return response()->json($item->load('category'), 201);
    }

    /**
     * Toggle availability of dish.
     */
    public function toggleAvailability(MenuItem $item): JsonResponse
    {
        $item->update(['is_available' => !$item->is_available]);

        return response()->json([
            'message' => "Item is now " . ($item->is_available ? 'available' : 'unavailable'),
            'item' => $item,
        ]);
    }
}
