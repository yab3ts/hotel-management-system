<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Services\TableStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    /**
     * List tables for current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RestaurantTable::query();

        if ($zone = $request->query('zone')) {
            $query->where('zone', $zone);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tables = $query->orderBy('table_number')->get();

        return response()->json($tables);
    }

    /**
     * Create a table.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_number' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'zone' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:available,occupied,reserved,maintenance'],
        ]);

        $table = RestaurantTable::create($validated);

        return response()->json($table, 201);
    }

    /**
     * Update table status with state machine checks.
     */
    public function updateStatus(Request $request, RestaurantTable $table): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,occupied,billing,cleaning,reserved,maintenance'],
        ]);

        TableStateMachine::transition($table, $validated['status'], $request->input('reason', 'Admin updated status'));

        return response()->json([
            'message' => 'Table status updated',
            'table' => $table,
        ]);
    }

    /**
     * Regenerate secure unguessable 128-bit cryptographic QR token.
     * Invalidates the previous token so old placards or unauthorized links stop working.
     */
    public function regenerateToken(Request $request, RestaurantTable $table): JsonResponse
    {
        $oldToken = $table->qr_token;
        $newToken = bin2hex(random_bytes(16)); // 16 bytes = 128 bits cryptographic entropy (32 hex characters)

        $table->update([
            'qr_token' => $newToken,
            'qr_version' => $table->qr_version + 1,
        ]);

        AuditLog::record('qr_token_regenerated', $table, [
            'old_token' => $oldToken,
            'new_token' => $newToken,
            'reason' => $request->input('reason', 'Admin requested token regeneration'),
        ]);

        return response()->json([
            'message' => "QR code regenerated for {$table->table_number}. Previous token is now invalid.",
            'qr_token' => $newToken,
            'table' => $table,
        ]);
    }

    /**
     * Toggle emergency pause of all QR ordering for a branch.
     */
    public function toggleQrOrdering(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $branch = $branchId ? Branch::findOrFail($branchId) : Branch::first();
        if (!$branch) {
            $branch = Branch::create([
                'tenant_id' => 1,
                'name' => 'Main Dining Room',
                'is_qr_ordering_enabled' => true,
            ]);
        }

        $branch->update([
            'is_qr_ordering_enabled' => !$branch->is_qr_ordering_enabled,
        ]);

        AuditLog::record('branch_qr_toggled', $branch, [
            'enabled' => $branch->is_qr_ordering_enabled,
        ]);

        return response()->json([
            'message' => $branch->is_qr_ordering_enabled 
                ? 'QR ordering enabled for this location.' 
                : 'QR ordering PAUSED for this location.',
            'is_qr_ordering_enabled' => $branch->is_qr_ordering_enabled,
        ]);
    }
}
