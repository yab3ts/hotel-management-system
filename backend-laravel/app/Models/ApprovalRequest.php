<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'approved_by',
        'action',
        'payload',
        'status',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
