<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'inventory';

    protected $fillable = [
        'tenant_id',
        'item_name',
        'category',
        'current_stock',
        'minimum_stock',
        'unit',
        'cost_per_unit',
        'supplier',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
            'cost_per_unit' => 'decimal:2',
        ];
    }
}
