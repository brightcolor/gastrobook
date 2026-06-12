<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Tenant;

/**
 * Request-scoped singleton holding the resolved tenant and location.
 * All tenant-scoped queries (via BelongsToTenant) read from this context.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Location $location = null;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function location(): ?Location
    {
        return $this->location;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->id;
    }

    public function locationId(): ?int
    {
        return $this->location?->id;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->location = null;
    }

    /**
     * Run a callback in the context of a given tenant, restoring the previous
     * context afterwards. Used by jobs, webhooks and the public booking flow.
     */
    public function runFor(Tenant $tenant, callable $callback, ?Location $location = null): mixed
    {
        $previousTenant = $this->tenant;
        $previousLocation = $this->location;

        $this->tenant = $tenant;
        $this->location = $location;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->location = $previousLocation;
        }
    }
}
