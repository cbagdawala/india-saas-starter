<?php

namespace App\Models;

use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Tenant, $this> workspaces the user belongs to (pivot: role) */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role')->withTimestamps();
    }

    /** @return BelongsTo<Tenant, $this> the workspace the user is currently acting in (server-side; switch via the API) */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /** The user's role in a workspace, or null when they are not a member. */
    public function roleIn(Tenant|int $tenant): ?TenantRole
    {
        $role = $this->tenants()->whereKey($tenant instanceof Tenant ? $tenant->getKey() : $tenant)->first()?->pivot->role;

        return $role ? TenantRole::from($role) : null;
    }
}
