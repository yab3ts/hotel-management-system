<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'table_id',
        'reservation_number',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_count',
        'reservation_date',
        'reservation_time',
        'status',
        'is_vip',
        'special_requests',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'reservation_date' => 'date',
            'is_vip' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
