<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Mail\TenantInvitationMail;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    /**
     * Invite an email address. The token is 48 random chars, emailed once; only its keyed hash (HMAC-SHA256 with
     * APP_KEY) is stored, so a database leak cannot be turned into working invite links. Re-inviting the same email
     * replaces any pending invitation.
     */
    public function invite(Tenant $tenant, User $inviter, string $email, TenantRole $role): TenantInvitation
    {
        $email = Str::lower(trim($email));

        if ($tenant->users()->whereRaw('lower(users.email) = ?', [$email])->exists()) {
            throw new TenancyException('That person is already a member of this workspace.', 422);
        }

        // Daily cap on invitations CREATED (revoked and replaced ones count): the endpoint sends mail to arbitrary addresses
        // and must not become a spam / phishing relay, however often an abuser revokes and re-invites.
        $sentToday = TenantInvitation::withTrashed()->where('created_at', '>=', now()->subDay())->count();
        if ($sentToday >= (int) config('tenancy.max_invitations_per_day')) {
            throw new TenancyException('Daily invitation limit reached for this workspace. Try again tomorrow.', 429);
        }

        // And per person, across every workspace they belong to or create: one account must not multiply the cap by
        // spinning up workspaces.
        $sentByUser = TenantInvitation::withoutTenancy()->withTrashed()->where('invited_by', $inviter->id)->where('created_at', '>=', now()->subDay())->count();
        if ($sentByUser >= (int) config('tenancy.max_invitations_per_user_per_day')) {
            throw new TenancyException('Daily invitation limit reached for your account. Try again tomorrow.', 429);
        }

        $pending = TenantInvitation::query()->whereNull('accepted_at')->where('expires_at', '>', now());
        if ((clone $pending)->where('email', '!=', $email)->count() >= (int) config('tenancy.max_pending_invitations')) {
            throw new TenancyException('Too many pending invitations. Revoke some first.', 422);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($pending, $email, $role, $inviter, $token) {
            (clone $pending)->where('email', $email)->delete();

            $invitation = new TenantInvitation(['email' => $email, 'role' => $role]);
            $invitation->token_hash = $this->hash($token);
            $invitation->invited_by = $inviter->id;
            $invitation->expires_at = now()->addDays((int) config('tenancy.invitation_ttl_days'));
            $invitation->save();

            return $invitation;
        });

        Mail::to($email)->send(new TenantInvitationMail(
            $tenant,
            $inviter->name,
            rtrim((string) config('app.url'), '/').'/invitations/accept?token='.$token,
            (int) config('tenancy.invitation_ttl_days'),
        ));

        return $invitation;
    }

    /** @return Collection<int, TenantInvitation> pending invitations of the current tenant */
    public function pending(): Collection
    {
        return TenantInvitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->latest('id')->get();
    }

    public function revoke(TenantInvitation $invitation): void
    {
        $invitation->delete();
    }

    /**
     * Accept as the signed-in user and switch them into the workspace. The lookup is by token hash across tenants
     * (the user has no membership yet, so withoutTenancy is intended here). Unknown token: 404. Used or expired: 410.
     * Signed-in email differs from the invited one: 403. The row is locked so a token cannot be redeemed twice.
     */
    public function accept(User $user, string $token): Tenant
    {
        return DB::transaction(function () use ($user, $token) {
            $invitation = TenantInvitation::withoutTenancy()->where('token_hash', $this->hash($token))->lockForUpdate()->first()
                ?? throw new TenancyException('Invitation not found.', 404);

            if ($invitation->accepted_at !== null || $invitation->expires_at->isPast()) {
                throw new TenancyException('This invitation has expired or was already used.', 410);
            }
            if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                throw new TenancyException('This invitation was sent to a different email address.', 403);
            }

            $tenant = Tenant::query()->find($invitation->tenant_id) ?? throw new TenancyException('Invitation not found.', 404);

            if (! $tenant->users()->whereKey($user->id)->exists()) {
                $tenant->users()->attach($user->id, ['role' => $invitation->role->value]);
            }
            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            return $tenant;
        });
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
