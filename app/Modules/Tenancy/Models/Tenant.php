<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A workspace. This is the tenant root itself, so it is NOT scoped by BelongsToTenant; access goes through a
 * user's memberships (User::tenants()).
 */
#[Fillable(['name', 'slug'])]
#[UsePolicy(TenantPolicy::class)]
class Tenant extends Model
{
    use SoftDeletes;

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function ownerCount(): int
    {
        return $this->users()->wherePivot('role', TenantRole::Owner->value)->count();
    }
}
