<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?string $tenantId = null;

    private bool $bypass = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->tenantId = $tenant->id;
        $this->bypass = false;
    }

    public function setById(string $tenantId): void
    {
        $this->tenant = null;
        $this->tenantId = $tenantId;
        $this->bypass = false;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->tenantId = null;
        $this->bypass = false;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenant !== null) {
            return $this->tenant;
        }

        if ($this->tenantId === null) {
            return null;
        }

        $this->tenant = Tenant::query()->find($this->tenantId);

        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    public function cacheKey(string $suffix): string
    {
        $prefix = $this->tenantId ?? 'global';

        return "t:{$prefix}:{$suffix}";
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant|string $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousTenantId = $this->tenantId;
        $previousBypass = $this->bypass;

        if ($tenant instanceof Tenant) {
            $this->set($tenant);
        } else {
            $this->setById($tenant);
        }

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->tenantId = $previousTenantId;
            $this->bypass = $previousBypass;
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $previousBypass = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previousBypass;
        }
    }
}
