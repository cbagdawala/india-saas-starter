<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\MissingTenantException;
use App\Modules\Tenancy\Models\Tenant;
use Closure;

/**
 * The tenant the current request (or job / test / seeder) is acting for. Registered as a scoped singleton.
 * Set by the `tenant` middleware after membership is verified, and always cleared when the request ends.
 * With no tenant set, the global scope fails closed (tenant-owned queries return nothing).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantRole $role = null;

    public function set(Tenant $tenant, ?TenantRole $role): void
    {
        $this->tenant = $tenant;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->role = null;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantOrFail(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantException('No current tenant.');
    }

    public function role(): ?TenantRole
    {
        return $this->role;
    }

    /**
     * Run code as a tenant outside a request (queue jobs, seeders, console). Authorisation is the caller's
     * responsibility: this does not check membership. Restores the previous context afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, Closure $callback, ?TenantRole $role = null): mixed
    {
        [$previousTenant, $previousRole] = [$this->tenant, $this->role];
        $this->set($tenant, $role);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->role = $previousRole;
        }
    }
}
