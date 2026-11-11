<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Exceptions\MissingTenantException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Put this on every model whose rows belong to a workspace (table has a `tenant_id` column). It adds the global
 * scope (queries, relations and route-model binding only see the current tenant), fills tenant_id on create and
 * refuses to move a row between tenants. tests/Feature/Tenancy/ArchitectureTest.php fails when a model with a
 * tenant_id or user_id column does not use it.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $current = app(TenantContext::class)->id();
            $explicit = $model->getAttribute('tenant_id');

            if ($explicit === null) {
                if ($current !== null) {
                    $model->setAttribute('tenant_id', $current);
                } elseif ($model->tenantIsRequired()) {
                    throw new MissingTenantException(sprintf('Cannot create %s without a current tenant.', $model::class));
                }
            } elseif ($current !== null && (int) $explicit !== $current) {
                throw new MissingTenantException(sprintf('Refusing to create %s in tenant %d while acting for tenant %d.', $model::class, $explicit, $current));
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new MissingTenantException(sprintf('%s cannot be moved between tenants.', $model::class));
            }
        });
    }

    /** Override and return false only where rows may legitimately exist outside any tenant (e.g. unlinked inbound email). */
    public function tenantIsRequired(): bool
    {
        return true;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Explicit, greppable escape hatch for system code that has no tenant by design (payment webhooks,
     * inbound email, the invitation acceptance lookup). Never call it from a user-facing read path.
     *
     * @return Builder<static>
     */
    public static function withoutTenancy(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
