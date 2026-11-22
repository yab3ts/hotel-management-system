<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'prep_time_minutes',
        'is_available',
        'is_spicy',
        'is_vegetarian',
        'is_vegan',
        'is_featured',
        'calories',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'prep_time_minutes' => 'integer',
            'is_available' => 'boolean',
            'is_spicy' => 'boolean',
            'is_vegetarian' => 'boolean',
            'is_vegan' => 'boolean',
            'is_featured' => 'boolean',
            'calories' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }
}
