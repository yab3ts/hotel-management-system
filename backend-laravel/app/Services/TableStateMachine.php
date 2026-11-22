<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RestaurantTable;
use Illuminate\Validation\ValidationException;

class TableStateMachine
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_BILLING = 'billing';
    public const STATUS_CLEANING = 'cleaning';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_MAINTENANCE = 'maintenance';

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_AVAILABLE => [self::STATUS_OCCUPIED, self::STATUS_RESERVED, self::STATUS_MAINTENANCE],
        self::STATUS_OCCUPIED => [self::STATUS_BILLING, self::STATUS_CLEANING, self::STATUS_MAINTENANCE],
        self::STATUS_BILLING => [self::STATUS_CLEANING, self::STATUS_OCCUPIED, self::STATUS_MAINTENANCE],
        self::STATUS_CLEANING => [self::STATUS_AVAILABLE, self::STATUS_MAINTENANCE],
        self::STATUS_RESERVED => [self::STATUS_OCCUPIED, self::STATUS_AVAILABLE, self::STATUS_MAINTENANCE],
        self::STATUS_MAINTENANCE => [self::STATUS_AVAILABLE],
    ];

    /**
     * Transition a table to a new status according to state machine rules.
     *
     * @throws ValidationException
     */
    public static function transition(RestaurantTable $table, string $newStatus, ?string $reason = null): RestaurantTable
    {
        $currentStatus = $table->status;

        if ($currentStatus === $newStatus) {
            return $table;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Invalid table transition from '{$currentStatus}' to '{$newStatus}'."],
            ]);
        }

        $table->update(['status' => $newStatus]);

        AuditLog::record('table_status_changed', $table, [
            'from' => $currentStatus,
            'to' => $newStatus,
            'reason' => $reason,
        ]);

        return $table;
    }
}
