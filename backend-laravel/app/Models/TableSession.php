<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableSession extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'table_id',
        'session_token',
        'status',
        'opened_at',
        'closed_at',
        'subtotal',
        'tax',
        'service_charge',
        'total',
        'paid_amount',
        'manager_override_settle',
        'settle_notes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(SessionGuest::class, 'session_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'session_id');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'session_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'session_id');
    }

    /**
     * Recalculate session totals from placed/accepted orders.
     */
    public function recalculateTotals(): void
    {
        $orders = $this->orders()->whereNotIn('status', ['cancelled'])->get();

        $subtotal = $orders->sum('subtotal');
        $tax = $orders->sum('tax');
        $service = $orders->sum('service_charge');
        $total = $orders->sum('total');

        $this->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $service,
            'total' => $total,
        ]);
    }
}
