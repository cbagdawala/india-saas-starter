<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantService
{
    /** Create a workspace with $owner as its owner. Switches the owner to it when $switch is true or they have none selected. */
    public function create(User $owner, string $name, bool $switch = true): Tenant
    {
        return DB::transaction(function () use ($owner, $name, $switch) {
            $name = Str::limit(trim($name), 120, '');
            $tenant = new Tenant(['name' => $name, 'slug' => $this->uniqueSlug($name)]);
            $tenant->save();
            $tenant->users()->attach($owner->id, ['role' => TenantRole::Owner->value]);

            if ($switch || $owner->current_tenant_id === null) {
                $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
            }

            return $tenant;
        });
    }

    /** Every user always has at least one workspace; registration (and leaving the last one) creates a personal one. */
    public function createPersonal(User $user): Tenant
    {
        return $this->create($user, $user->name."'s workspace");
    }

    /** The user's own workspace: the first one they own (registration creates it). Null when they own none. */
    public function personalWorkspaceId(User $user): ?int
    {
        $id = $user->tenants()->wherePivot('role', TenantRole::Owner->value)->orderBy('tenant_user.id')->value('tenants.id');

        return $id === null ? null : (int) $id;
    }

    /** @return Collection<int, Tenant> workspaces the user belongs to, each with pivot role */
    public function workspacesFor(User $user): Collection
    {
        return $user->tenants()->orderBy('tenants.name')->get();
    }

    /** @throws TenancyException when the user is not a member (reported as not found so ids cannot be probed) */
    public function switchTo(User $user, int $tenantId): Tenant
    {
        $tenant = $user->tenants()->whereKey($tenantId)->first() ?? throw new TenancyException('Workspace not found.', 404);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return $tenant;
    }

    /** @return Collection<int, User> */
    public function members(Tenant $tenant): Collection
    {
        return $tenant->users()->orderBy('users.name')->get();
    }

    /**
     * The policy already checked $actor before this call; it is checked again here, under the workspace lock, because
     * the role it saw may have changed meanwhile (an owner demoted a second before this request committed).
     */
    public function changeRole(Tenant $tenant, int $userId, TenantRole $role, User $actor): void
    {
        DB::transaction(function () use ($tenant, $userId, $role, $actor) {
            $this->lock($tenant);
            if ($actor->roleIn($tenant) !== TenantRole::Owner) {
                throw new TenancyException('Only an owner can change roles.', 403);
            }
            $current = $this->roleOrFail($tenant, $userId);

            if ($current === TenantRole::Owner && $role !== TenantRole::Owner && $tenant->ownerCount() <= 1) {
                throw new TenancyException('A workspace needs at least one owner. Promote another owner first.', 422);
            }

            $tenant->users()->updateExistingPivot($userId, ['role' => $role->value]);
        });
    }

    /** Remove another member (authorisation, i.e. who may remove whom, is the policy's job). */
    public function removeMember(Tenant $tenant, int $userId, User $actor): void
    {
        $this->detach($tenant, $userId, 'The last owner cannot be removed.', $actor);
    }

    public function leave(Tenant $tenant, User $user): void
    {
        $this->detach($tenant, $user->id, 'The last owner cannot leave. Promote another owner or delete the workspace.');
    }

    private function detach(Tenant $tenant, int $userId, string $lastOwnerMessage, ?User $actor = null): void
    {
        DB::transaction(function () use ($tenant, $userId, $lastOwnerMessage, $actor) {
            $this->lock($tenant);
            $role = $this->roleOrFail($tenant, $userId);

            // Re-check, under the lock, that the acting member may still remove this one (owner: anyone; admin: members only).
            if ($actor !== null && $actor->id !== $userId) {
                $allowed = match ($actor->roleIn($tenant)) {
                    TenantRole::Owner => true,
                    TenantRole::Admin => $role === TenantRole::Member,
                    default => false,
                };
                if (! $allowed) {
                    throw new TenancyException('You are not allowed to remove this member.', 403);
                }
            }

            if ($role === TenantRole::Owner && $tenant->ownerCount() <= 1) {
                throw new TenancyException($lastOwnerMessage, 422);
            }

            $tenant->users()->detach($userId);

            // Keep the user usable: point them at another workspace, or give them a fresh personal one.
            $user = User::query()->findOrFail($userId);
            if ((int) $user->current_tenant_id === $tenant->id) {
                $next = $user->tenants()->orderBy('tenants.id')->first();
                $user->forceFill(['current_tenant_id' => $next?->id])->save();
            }
            if (! $user->tenants()->exists()) {
                $this->createPersonal($user);
            }
        });
    }

    /** Serialises membership changes per workspace so two simultaneous demotions cannot both pass the last-owner check. */
    private function lock(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
    }

    private function roleOrFail(Tenant $tenant, int $userId): TenantRole
    {
        $member = $tenant->users()->whereKey($userId)->first() ?? throw new TenancyException('Member not found.', 404);

        return TenantRole::from($member->pivot->role);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 60, '')) ?: 'workspace';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Tenant::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
