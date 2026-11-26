<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlert extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'severity',
        'category',
        'description',
        'ip_address',
        'metadata',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function raise(
        string $category,
        string $description,
        string $severity = 'medium',
        ?int $userId = null,
        array $metadata = [],
        ?int $tenantId = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId ?? \App\Tenancy\TenantContext::getTenantId(),
            'user_id' => $userId ?? auth()->id(),
            'severity' => $severity,
            'category' => $category,
            'description' => $description,
            'ip_address' => request()->ip(),
            'metadata' => $metadata,
        ]);
    }
}
