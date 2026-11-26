<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'current_tenant_id',
        'branch_id',
        'is_active',
        'must_change_password',
        'mfa_enabled',
        'mfa_secret',
        'mfa_recovery_codes',
        'is_super_admin',
        'is_vip',
        'impersonation_consent_token',
        'impersonation_consented_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
        'impersonation_consent_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'is_super_admin' => 'boolean',
            'is_vip' => 'boolean',
            'impersonation_consented_until' => 'datetime',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    public function rolesForTenant(?int $tenantId = null): array
    {
        $id = $tenantId ?? $this->current_tenant_id;
        if (!$id) return ['customer'];

        $roles = $this->tenants()
            ->where('tenants.id', $id)
            ->pluck('role')
            ->toArray();

        // Ensure aliases like kitchen/kitchen_staff are normalized
        if (in_array('kitchen_staff', $roles) && !in_array('kitchen', $roles)) {
            $roles[] = 'kitchen';
        }
        if (in_array('kitchen', $roles) && !in_array('kitchen_staff', $roles)) {
            $roles[] = 'kitchen_staff';
        }

        return !empty($roles) ? array_values(array_unique($roles)) : ['customer'];
    }

    public function hasRoleInTenant(string|array $roles, ?int $tenantId = null): bool
    {
        $tenantRoles = $this->rolesForTenant($tenantId);
        $roles = (array) $roles;

        foreach ($roles as $role) {
            if (in_array($role, $tenantRoles, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $role): bool
    {
        return $this->hasRoleInTenant($role);
    }

    public function isAdmin(): bool
    {
        return $this->hasRoleInTenant('admin');
    }
}
