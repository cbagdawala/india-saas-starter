<?php

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Role matrix (owner > admin > member):
 *  - view / leave:                 any member (leave is further limited by the last-owner rule in TenantService)
 *  - list + invite + revoke:       owner, admin (an admin can only invite members)
 *  - remove a member:              owner (anyone), admin (members only)
 *  - change a role, manage billing: owner only
 *  - billing profile (invoice buyer details): owner, admin
 */
class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant) !== null;
    }

    public function leave(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant);
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant)?->isAdmin() ?? false;
    }

    public function invite(User $user, Tenant $tenant, TenantRole $invitedRole): bool
    {
        return match ($user->roleIn($tenant)) {
            TenantRole::Owner => $invitedRole !== TenantRole::Owner, // ownership is granted by promotion, not by invitation
            TenantRole::Admin => $invitedRole === TenantRole::Member,
            default => false,
        };
    }

    public function removeMember(User $user, Tenant $tenant, TenantRole $targetRole): bool
    {
        return match ($user->roleIn($tenant)) {
            TenantRole::Owner => true,
            TenantRole::Admin => $targetRole === TenantRole::Member,
            default => false,
        };
    }

    public function changeRole(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant) === TenantRole::Owner;
    }

    public function manageBillingProfile(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant)?->isAdmin() ?? false;
    }

    public function manageBilling(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant) === TenantRole::Owner;
    }
}
