<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IotDevice extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'device_type',
        'device_identifier',
        'credential_hash',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = ['credential_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
