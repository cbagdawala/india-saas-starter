<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\AccountErasureBlocked;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Notes\Models\Note;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personal data: export (DPDP right of access/portability) and erasure.
 *
 * Erasure removes the account and everything that identifies the person, EXCEPT issued GST invoices and credit notes:
 * those are statutory records the operator must keep (CGST Act s.36: 72 months), they hold the buyer snapshot as it was
 * issued, and they never reference the user (only the workspace). A workspace the user alone owned is emptied
 * (notes, tickets, invitations), its profile scrubbed and the row soft-deleted so its invoices keep a valid parent.
 */
class AccountService
{
    /** @return list<string> reasons erasure cannot go ahead (empty = it can) */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach ($this->ownedWorkspaces($user) as $tenant) {
            if ($tenant->ownerCount() > 1) {
                continue; // another owner stays behind and inherits the subscription (see erase())
            }
            if ($tenant->users()->where('users.id', '!=', $user->id)->exists()) {
                $blockers[] = "You are the only owner of \"{$tenant->name}\", which has other members. Make someone else an owner or remove the members first.";
            }
            if (Subscription::withoutTenancy()->where('tenant_id', $tenant->id)->whereIn('status', ['active', 'past_due'])->exists()) {
                $blockers[] = "The workspace \"{$tenant->name}\" has an active subscription. Cancel it first.";
            }
        }

        return $blockers;
    }

    /**
     * @throws AccountErasureBlocked when something changed since blockers() was read (checked again under lock)
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Lock the workspaces the user owns, then decide: a concurrent promotion, removal or checkout cannot slip between
            // the check and the delete.
            Tenant::query()->whereIn('id', $this->ownedWorkspaces($user)->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            if (($blockers = $this->blockers($user)) !== []) {
                throw new AccountErasureBlocked($blockers);
            }

            foreach ($this->ownedWorkspaces($user) as $tenant) {
                if ($tenant->ownerCount() <= 1 && ! $tenant->users()->where('users.id', '!=', $user->id)->exists()) {
                    $this->scrubWorkspace($tenant);
                }
            }

            // Billing belongs to the workspace: hand the subscriptions this user purchased to a remaining owner (user_id is
            // nullable with ON DELETE SET NULL, so even without one the row survives, only unattributed).
            Subscription::withoutTenancy()->where('user_id', $user->id)->get()->each(function (Subscription $subscription) use ($user) {
                $newOwner = DB::table('tenant_user')->where('tenant_id', $subscription->tenant_id)->where('role', TenantRole::Owner->value)
                    ->where('user_id', '!=', $user->id)->orderBy('id')->value('user_id');
                $subscription->forceFill(['user_id' => $newOwner])->save();
            });

            $this->ticketsOf($user)->delete();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // Cascades: tenant_user. Set to NULL: the author of notes, the purchaser of subscriptions. Invoices are kept.
            $user->delete();
        });
    }

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $workspaces = $user->tenants()->get();
        $workspaceIds = $workspaces->pluck('id');
        $ownedIds = $workspaces->filter(fn (Tenant $t) => $t->pivot->role === TenantRole::Owner->value)->pluck('id');

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'onboarded_at', 'landing_variant', 'created_at']),
            'workspaces' => $workspaces->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name, 'role' => $t->pivot->role, 'joined_at' => $t->pivot->created_at?->toIso8601String()])->values(),
            // Only workspaces the user belongs to today: a removed member must not read a workspace's notes through this endpoint.
            'notes' => Note::withoutTenancy()->where('user_id', $user->id)->whereIn('tenant_id', $workspaceIds)->get(['id', 'tenant_id', 'title', 'body', 'created_at', 'updated_at']),
            'support_tickets' => $this->ticketsOf($user)->get(['id', 'source', 'from_email', 'from_name', 'subject', 'body_text', 'status', 'received_at']),
            'subscriptions_purchased' => Subscription::withoutTenancy()->where('user_id', $user->id)->whereIn('tenant_id', $workspaceIds)->get(['id', 'tenant_id', 'plan', 'status', 'amount', 'currency', 'created_at']),
            'invitations_sent' => TenantInvitation::withoutTenancy()->withTrashed()->where('invited_by', $user->id)->get(['id', 'tenant_id', 'email', 'role', 'created_at']),
            'invoices_of_owned_workspaces' => Invoice::withoutTenancy()->whereIn('tenant_id', $ownedIds)->get(['id', 'tenant_id', 'type', 'number', 'issued_at', 'total', 'currency']),
        ];
    }

    /**
     * The user's tickets: those linked to their account, plus inbound mail from their address ONLY when the account's email
     * is verified and the mail itself passed sender verification. An unverified account (anyone can register an address they
     * do not own) therefore never reaches, exports or deletes someone else's mail.
     *
     * @return Builder<SupportTicket>
     */
    private function ticketsOf(User $user): Builder
    {
        return SupportTicket::withoutTenancy()->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->hasVerifiedEmail()) {
                $q->orWhere(fn ($m) => $m->where('from_email', $user->email)->where('sender_verified', true));
            }
        });
    }

    /** @return Collection<int, Tenant> */
    private function ownedWorkspaces(User $user)
    {
        return $user->tenants()->wherePivot('role', TenantRole::Owner->value)->get();
    }

    private function scrubWorkspace(Tenant $tenant): void
    {
        Note::withoutTenancy()->where('tenant_id', $tenant->id)->delete();
        SupportTicket::withoutTenancy()->where('tenant_id', $tenant->id)->delete();
        TenantInvitation::withoutTenancy()->withTrashed()->where('tenant_id', $tenant->id)->forceDelete();
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->delete();

        $tenant->forceFill([
            'name' => 'Deleted workspace', 'slug' => 'deleted-'.Str::lower(Str::random(12)),
            'legal_name' => null, 'address_line1' => null, 'address_line2' => null, 'city' => null, 'pincode' => null,
            'gstin' => null, 'state_code' => null,
        ])->save();
        $tenant->delete(); // soft delete: invoices keep their parent row
    }
}
