<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    protected static ?Tenant $tenant = null;

    public static function setTenant(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
    }

    public static function getTenant(): ?Tenant
    {
        return static::$tenant;
    }

    public static function getTenantId(): ?int
    {
        return static::$tenant?->id;
    }

    public static function hasTenant(): bool
    {
        return static::$tenant !== null;
    }

    public static function clear(): void
    {
        static::$tenant = null;
    }
}
