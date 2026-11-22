<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'slug',
        'phone',
        'address',
        'currency',
        'tax_rate',
        'service_charge',
        'opening_time',
        'closing_time',
        'is_qr_ordering_enabled',
        'max_order_amount',
        'session_timeout_minutes',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'max_order_amount' => 'decimal:2',
            'is_qr_ordering_enabled' => 'boolean',
            'session_timeout_minutes' => 'integer',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }
}
