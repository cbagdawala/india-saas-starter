<?php

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Mail\TenantInvitationMail;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    spa();
    Mail::fake();
});

function workspaceWithOwner(): array
{
    $owner = User::factory()->create();

    return [$owner, $owner->currentTenant];
}

it('lists the user workspaces with their role and creates another one they own', function () {
    [$owner, $tenant] = workspaceWithOwner();

    $this->actingAs($owner)->getJson('/api/tenants')->assertOk()
        ->assertJsonPath('data.0.id', $tenant->id)->assertJsonPath('data.0.role', 'owner')->assertJsonPath('data.0.is_current', true);

    $created = $this->actingAs($owner)->postJson('/api/tenants', ['name' => 'Acme Pvt Ltd'])->assertCreated()
        ->assertJsonPath('data.role', 'owner')->assertJsonPath('data.is_current', true)->json('data');
    expect($owner->fresh()->current_tenant_id)->toBe($created['id']);

    $this->actingAs($owner)->postJson('/api/tenants', ['name' => 'x'])->assertUnprocessable();
});

it('invites by email, stores only a hash, and the invitee joins on accept', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'New@Example.com', 'role' => 'member'])
        ->assertCreated()->assertJsonPath('data.email', 'new@example.com')->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.token_hash');

    Mail::assertSent(TenantInvitationMail::class, fn ($m) => $m->hasTo('new@example.com'));
    $token = lastInviteToken();
    expect($token)->toHaveLength(48);

    $stored = DB::table('tenant_invitations')->sole();
    expect($stored->token_hash)->not->toBe($token)->and($stored->token_hash)->toBe(hash_hmac('sha256', $token, (string) config('app.key')))
        ->and(json_encode($stored))->not->toContain($token);

    $this->actingAs($invitee)->postJson('/api/invitations/accept', ['token' => $token])
        ->assertOk()->assertJsonPath('data.id', $tenant->id)->assertJsonPath('data.role', 'member')->assertJsonPath('data.is_current', true);

    expect($invitee->fresh()->roleIn($tenant))->toBe(TenantRole::Member)->and($invitee->fresh()->current_tenant_id)->toBe($tenant->id);
});

it('rejects an expired invitation', function () {
    [$owner] = workspaceWithOwner();
    $invitee = User::factory()->create(['email' => 'late@example.com']);
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'late@example.com', 'role' => 'member'])->assertCreated();
    $token = lastInviteToken();

    $this->travel((int) config('tenancy.invitation_ttl_days') + 1)->days();

    $this->actingAs($invitee)->postJson('/api/invitations/accept', ['token' => $token])->assertStatus(410);
    expect($invitee->tenants()->count())->toBe(1); // only the personal one
});

it('rejects reusing an invitation, even by the right person', function () {
    [$owner] = workspaceWithOwner();
    $invitee = User::factory()->create(['email' => 'once@example.com']);
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'once@example.com', 'role' => 'admin'])->assertCreated();
    $token = lastInviteToken();

    $this->actingAs($invitee)->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
    $this->actingAs($invitee)->postJson('/api/invitations/accept', ['token' => $token])->assertStatus(410);
    // and not by someone else after the fact
    $this->actingAs(User::factory()->create(['email' => 'thief@example.com']))->postJson('/api/invitations/accept', ['token' => $token])->assertStatus(410);
});

it('rejects an invitation accepted by a different email, and keeps it usable for the right one', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'right@example.com', 'role' => 'member'])->assertCreated();
    $token = lastInviteToken();
    $wrong = User::factory()->create(['email' => 'wrong@example.com']);

    $this->actingAs($wrong)->postJson('/api/invitations/accept', ['token' => $token])->assertForbidden();
    expect($wrong->fresh()->roleIn($tenant))->toBeNull();

    $this->actingAs(User::factory()->create(['email' => 'RIGHT@example.com']))->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
});

it('rejects unknown and malformed tokens', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/invitations/accept', ['token' => str_repeat('a', 48)])->assertNotFound();
    $this->actingAs($user)->postJson('/api/invitations/accept', ['token' => 'short'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/invitations/accept', [])->assertUnprocessable();
});

it('requires a signed-in user to accept an invitation', function () {
    $this->postJson('/api/invitations/accept', ['token' => str_repeat('a', 48)])->assertUnauthorized();
});

it('replaces a pending invitation when the same email is invited again', function () {
    [$owner] = workspaceWithOwner();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertCreated();
    $old = lastInviteToken();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'admin'])->assertCreated();

    expect(TenantInvitation::withoutTenancy()->count())->toBe(1);
    $this->actingAs(User::factory()->create(['email' => 'a@example.com']))->postJson('/api/invitations/accept', ['token' => $old])->assertNotFound();
});

it('refuses to invite an existing member and caps pending invitations', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $member = addMember($tenant, User::factory()->create(['email' => 'in@example.com']));

    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'IN@example.com', 'role' => 'member'])->assertUnprocessable();

    config(['tenancy.max_pending_invitations' => 1]);
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'one@example.com', 'role' => 'member'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'two@example.com', 'role' => 'member'])->assertUnprocessable();
});

it('validates the invite payload (an owner cannot be invited)', function () {
    [$owner] = workspaceWithOwner();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'nope', 'role' => 'member'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
});

it('enforces the invite role matrix', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($tenant, User::factory()->create());

    $this->actingAs($member)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertForbidden();
    $this->actingAs($member)->getJson('/api/tenant/invitations')->assertForbidden();
    $this->actingAs($admin)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($admin)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertCreated();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'b@example.com', 'role' => 'admin'])->assertCreated();
    $this->actingAs($admin)->getJson('/api/tenant/invitations')->assertOk()->assertJsonCount(2, 'data');
});

it('lets owners and admins revoke invitations, and 404s other workspaces invitations', function () {
    [$owner] = workspaceWithOwner();
    [$otherOwner] = workspaceWithOwner();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertCreated();
    $id = TenantInvitation::withoutTenancy()->sole()->id;

    $this->actingAs($otherOwner)->deleteJson("/api/tenant/invitations/{$id}")->assertNotFound();
    $this->actingAs($owner)->deleteJson("/api/tenant/invitations/{$id}")->assertOk();
    expect(TenantInvitation::withoutTenancy()->count())->toBe(0);
});

it('lists members of the current workspace only', function () {
    [$owner, $tenant] = workspaceWithOwner();
    addMember($tenant, User::factory()->create(), TenantRole::Member, false);
    User::factory()->create(); // elsewhere

    $this->actingAs($owner)->getJson('/api/tenant/members')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.password');
    $this->actingAs($owner)->getJson('/api/tenant')->assertOk()->assertJsonPath('data.role', 'owner');
});

it('lets only an owner change roles', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($tenant, User::factory()->create());

    $this->actingAs($admin)->patchJson("/api/tenant/members/{$member->id}", ['role' => 'admin'])->assertForbidden();
    $this->actingAs($member)->patchJson("/api/tenant/members/{$member->id}", ['role' => 'owner'])->assertForbidden();
    $this->actingAs($owner)->patchJson("/api/tenant/members/{$member->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
    expect($member->fresh()->roleIn($tenant))->toBe(TenantRole::Admin);

    $this->actingAs($owner)->patchJson("/api/tenant/members/{$member->id}", ['role' => 'superuser'])->assertUnprocessable();
    $this->actingAs($owner)->patchJson('/api/tenant/members/99999', ['role' => 'admin'])->assertNotFound();
});

it('cannot demote, remove or let the last owner leave', function () {
    [$owner, $tenant] = workspaceWithOwner();

    $this->actingAs($owner)->patchJson("/api/tenant/members/{$owner->id}", ['role' => 'admin'])->assertUnprocessable();
    $this->actingAs($owner)->deleteJson("/api/tenant/members/{$owner->id}")->assertUnprocessable();
    $this->actingAs($owner)->postJson('/api/tenant/leave')->assertUnprocessable();
    expect($owner->fresh()->roleIn($tenant))->toBe(TenantRole::Owner);

    // With a second owner the first may step down or leave.
    $second = addMember($tenant, User::factory()->create(), TenantRole::Owner, false);
    $this->actingAs($owner)->patchJson("/api/tenant/members/{$owner->id}", ['role' => 'member'])->assertOk();
    // ...and now the second one is the last owner.
    $this->actingAs($second)->postJson('/api/tenant/leave')->assertUnprocessable();
    $this->actingAs($second)->patchJson("/api/tenant/members/{$second->id}", ['role' => 'admin'])->assertUnprocessable();
});

it('enforces who may remove whom', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $admin2 = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($tenant, User::factory()->create());
    $member2 = addMember($tenant, User::factory()->create());

    $this->actingAs($member)->deleteJson("/api/tenant/members/{$member2->id}")->assertForbidden();
    $this->actingAs($admin)->deleteJson("/api/tenant/members/{$admin2->id}")->assertForbidden();
    $this->actingAs($admin)->deleteJson("/api/tenant/members/{$owner->id}")->assertForbidden();
    $this->actingAs($admin)->deleteJson("/api/tenant/members/{$member2->id}")->assertOk();
    $this->actingAs($owner)->deleteJson("/api/tenant/members/{$admin2->id}")->assertOk();
    $this->actingAs($owner)->deleteJson('/api/tenant/members/99999')->assertNotFound();
});

it('cuts off a removed member immediately and gives them a usable workspace', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $member = User::factory()->create();
    $personal = $member->currentTenant;
    addMember($tenant, $member);
    $this->actingAs($member)->postJson('/api/notes', ['title' => 'Mine'])->assertCreated();

    $this->actingAs($owner)->deleteJson("/api/tenant/members/{$member->id}")->assertOk();

    expect($member->fresh()->current_tenant_id)->toBe($personal->id);
    $this->actingAs($member->fresh())->getJson('/api/notes')->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($owner)->getJson('/api/notes')->assertJsonPath('data.meta.total', 1); // their note stays with the workspace
});

it('lets a member leave, and creates a personal workspace if it was their only one', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $member = User::factory()->create();
    addMember($tenant, $member);
    $member->tenants()->detach($member->tenants()->where('tenants.id', '!=', $tenant->id)->first()->id); // only this workspace left

    $this->actingAs($member)->postJson('/api/tenant/leave')->assertOk();

    $fresh = $member->fresh();
    expect($fresh->tenants()->count())->toBe(1)->and($fresh->current_tenant_id)->not->toBe($tenant->id)->and($fresh->current_tenant_id)->not->toBeNull();
    $this->actingAs($fresh)->getJson('/api/notes')->assertOk();
});

it('lets two owners hand over: promote, then the original owner leaves', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $member = addMember($tenant, User::factory()->create(), TenantRole::Member, false);

    $this->actingAs($owner)->patchJson("/api/tenant/members/{$member->id}", ['role' => 'owner'])->assertOk();
    $this->actingAs($owner)->postJson('/api/tenant/leave')->assertOk();
    expect($tenant->fresh()->ownerCount())->toBe(1)->and($member->fresh()->roleIn($tenant))->toBe(TenantRole::Owner);
});

it('sends a fixed subject and escapes the workspace name in the body', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $tenant->forceFill(['name' => 'ACTION REQUIRED [click here](https://evil.test) <b>x</b>'])->save();

    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'target@example.com', 'role' => 'member'])->assertCreated();

    Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $m) {
        $m->assertHasSubject("You've been invited to a workspace on ".config('app.name'));
        $html = $m->render();

        return ! str_contains($m->envelope()->subject, 'ACTION REQUIRED')
            && str_contains($html, '&lt;b&gt;x&lt;/b&gt;')
            && ! str_contains($html, '<b>x</b>')
            && ! str_contains($html, '<a href="https://evil.test"');
    });
});

it('caps invitations per workspace per day, including revoked and replaced ones', function () {
    config(['tenancy.max_invitations_per_day' => 3]);
    [$owner] = workspaceWithOwner();

    foreach (['a', 'b'] as $name) {
        $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => "{$name}@example.com", 'role' => 'member'])->assertCreated();
    }
    $id = TenantInvitation::withoutTenancy()->where('email', 'a@example.com')->value('id');
    $this->actingAs($owner)->deleteJson("/api/tenant/invitations/{$id}")->assertOk();                                          // revoke
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'b@example.com', 'role' => 'admin'])->assertCreated(); // replace

    // 3 created so far (one revoked, one replaced): the cap holds even though only 1 is pending.
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'c@example.com', 'role' => 'member'])->assertStatus(429);

    $this->travel(25)->hours();
    $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => 'c@example.com', 'role' => 'member'])->assertCreated();
});

it('requires a verified email to invite people or create workspaces', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/tenant/invitations', ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
    $this->actingAs($user)->postJson('/api/tenants', ['name' => 'Spam Co'])->assertForbidden();
    $this->actingAs($user)->getJson('/api/tenants')->assertOk();
});

it('re-checks the acting member role inside the locked transaction', function () {
    [$owner, $tenant] = workspaceWithOwner();
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($tenant, User::factory()->create());
    $service = app(TenantService::class);

    // The policy saw an owner, but by the time the service locks the workspace this actor is only a member / admin.
    $demoted = User::factory()->create();
    addMember($tenant, $demoted, TenantRole::Member, false);

    expect(fn () => $service->changeRole($tenant, $member->id, TenantRole::Owner, $demoted))->toThrow(TenancyException::class, 'Only an owner');
    expect(fn () => $service->changeRole($tenant, $member->id, TenantRole::Admin, $admin))->toThrow(TenancyException::class);
    expect(fn () => $service->removeMember($tenant, $admin->id, $admin2 = addMember($tenant, User::factory()->create(), TenantRole::Admin, false)))->toThrow(TenancyException::class);
    expect(fn () => $service->removeMember($tenant, $owner->id, $admin))->toThrow(TenancyException::class);

    expect($member->fresh()->roleIn($tenant))->toBe(TenantRole::Member);
    $service->changeRole($tenant, $member->id, TenantRole::Admin, $owner);
    expect($member->fresh()->roleIn($tenant))->toBe(TenantRole::Admin);
});

it('caps invitations per person across workspaces, so extra workspaces do not multiply the cap', function () {
    config(['tenancy.max_invitations_per_user_per_day' => 3, 'tenancy.max_invitations_per_day' => 100]);
    $owner = User::factory()->create();
    $service = app(TenantService::class);

    foreach (['a', 'b'] as $n) {
        $this->actingAs($owner)->postJson('/api/tenant/invitations', ['email' => "{$n}@example.com", 'role' => 'member'])->assertCreated();
    }
    $service->create($owner, 'Second workspace'); // fresh workspace, fresh per-workspace budget ...
    $this->actingAs($owner->fresh())->postJson('/api/tenant/invitations', ['email' => 'c@example.com', 'role' => 'member'])->assertCreated();
    $this->actingAs($owner->fresh())->postJson('/api/tenant/invitations', ['email' => 'd@example.com', 'role' => 'member'])->assertStatus(429); // ... but not a fresh personal one

    $this->travel(25)->hours();
    $this->actingAs($owner->fresh())->postJson('/api/tenant/invitations', ['email' => 'd@example.com', 'role' => 'member'])->assertCreated();
});
