<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'domain',
        'domain_verification_token',
        'domain_verified_at',
        'encryption_key_salt',
        'webhook_secret',
        'payout_iban',
        'payout_account_name',
        'email',
        'phone',
        'address',
        'currency',
        'timezone',
        'settings',
        'is_active',
    ];

    protected $hidden = [
        'encryption_key_salt',
        'webhook_secret',
        'domain_verification_token',
        'payout_iban',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'domain_verified_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'payout_iban' => 'encrypted',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
