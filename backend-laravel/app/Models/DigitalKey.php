<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigitalKey extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'table_session_id',
        'reservation_id',
        'room_or_table_label',
        'key_token_hash',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['key_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at && $this->expires_at->isFuture();
    }
}
