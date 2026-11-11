<?php

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Notes\Models\Note;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\MissingTenantException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\TenantContext;

beforeEach(fn () => spa());

it('registration creates a personal workspace owned by the new user and selects it', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'Secret-pass-1', 'password_confirmation' => 'Secret-pass-1',
    ])->assertCreated();

    $user = User::sole();
    $tenant = Tenant::sole();
    expect($user->current_tenant_id)->toBe($tenant->id)
        ->and($tenant->name)->toBe("Asha's workspace")
        ->and($user->roleIn($tenant))->toBe(TenantRole::Owner);

    $this->getJson('/api/auth/me')->assertJsonPath('data.current_tenant_id', $tenant->id);
});

it('stops user A from reading, updating or deleting user B notes by id', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $note = Note::factory()->for($b)->create(['title' => 'B secret']);

    $this->actingAs($a)->getJson("/api/notes/{$note->id}")->assertNotFound();
    $this->actingAs($a)->putJson("/api/notes/{$note->id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($a)->patchJson("/api/notes/{$note->id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($a)->deleteJson("/api/notes/{$note->id}")->assertNotFound();

    expect($note->fresh()->title)->toBe('B secret');
    expect(Note::withoutTenancy()->whereKey($note->id)->exists())->toBeTrue();
});

it('lists only the current workspace and follows a switch', function () {
    $user = User::factory()->create();
    $first = $user->currentTenant;
    Note::factory()->count(2)->for($user)->create();
    Note::factory()->count(3)->create(); // other tenants

    $second = app(TenantService::class)->create($user, 'Second');
    $this->actingAs($user);
    $this->getJson('/api/notes')->assertJsonPath('data.meta.total', 0); // freshly created workspace is selected, empty

    $this->postJson('/api/notes', ['title' => 'In second'])->assertCreated();
    expect(Note::withoutTenancy()->where('title', 'In second')->value('tenant_id'))->toBe($second->id);

    $this->postJson("/api/tenants/{$first->id}/switch")->assertOk()->assertJsonPath('data.is_current', true);
    $this->getJson('/api/notes')->assertJsonPath('data.meta.total', 2);
});

it('ignores a tenant_id or user_id sent by the client', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->postJson('/api/notes', ['title' => 'x', 'tenant_id' => $b->current_tenant_id, 'user_id' => $b->id])->assertCreated();

    $note = Note::withoutTenancy()->sole();
    expect($note->tenant_id)->toBe($a->current_tenant_id)->and($note->user_id)->toBe($a->id);
});

it('rejects switching to a workspace the user does not belong to', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->postJson("/api/tenants/{$b->current_tenant_id}/switch")->assertNotFound();
    $this->actingAs($a)->postJson('/api/tenants/99999/switch')->assertNotFound();
    expect($a->fresh()->current_tenant_id)->not->toBe($b->current_tenant_id);
});

it('rejects every tenant route when the current workspace is not one the user belongs to', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    // Stale / tampered pointer: current_tenant_id points at someone else's workspace.
    $a->forceFill(['current_tenant_id' => $b->current_tenant_id])->save();

    $this->actingAs($a)->getJson('/api/notes')->assertForbidden();
    $this->actingAs($a)->postJson('/api/notes', ['title' => 'x'])->assertForbidden();
    $this->actingAs($a)->getJson('/api/billing/subscription')->assertForbidden();
    $this->actingAs($a)->getJson('/api/support/tickets')->assertForbidden();
    $this->actingAs($a)->getJson('/api/tenant/members')->assertForbidden();
    expect(Note::withoutTenancy()->count())->toBe(0);

    // ...while user-level routes still let them recover.
    $this->actingAs($a)->getJson('/api/tenants')->assertOk();
    $this->actingAs($a)->postJson('/api/tenants/'.$a->tenants()->first()->id.'/switch')->assertOk();
    $this->actingAs($a)->getJson('/api/notes')->assertOk();
});

it('rejects tenant routes when no workspace is selected or the workspace was deleted', function () {
    $a = User::factory()->create();
    $tenant = $a->currentTenant;

    $tenant->delete(); // soft delete
    $this->actingAs($a)->getJson('/api/notes')->assertForbidden();

    $a->forceFill(['current_tenant_id' => null])->save();
    $this->actingAs($a)->getJson('/api/notes')->assertForbidden();
});

it('requires authentication on tenant routes', function () {
    $this->getJson('/api/notes')->assertUnauthorized();
    $this->getJson('/api/tenant/members')->assertUnauthorized();
    $this->getJson('/api/tenants')->assertUnauthorized();
});

it('fails closed: no tenant in context means no rows, and creation without a tenant throws', function () {
    $user = User::factory()->create();
    Note::factory()->for($user)->create();

    expect(Note::query()->count())->toBe(0);
    expect(fn () => Note::query()->create(['title' => 'orphan']))->toThrow(MissingTenantException::class);

    app(TenantContext::class)->runAs($user->currentTenant, function () {
        expect(Note::query()->count())->toBe(1);
    });
    expect(Note::query()->count())->toBe(0); // restored
});

it('refuses to write across tenants or move a row between tenants', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $note = Note::factory()->for($a)->create();

    expect(fn () => app(TenantContext::class)->runAs($a->currentTenant, fn () => Note::factory()->for($b)->create(['tenant_id' => $b->current_tenant_id])))
        ->toThrow(MissingTenantException::class);

    $note->tenant_id = $b->current_tenant_id;
    expect(fn () => $note->save())->toThrow(MissingTenantException::class);
});

it('does not leak the tenant context between requests', function () {
    $a = User::factory()->create();
    $this->actingAs($a)->getJson('/api/notes')->assertOk();

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('lets members of one workspace share notes, with author-or-admin write access', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $member = addMember($tenant, User::factory()->create());
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $note = Note::factory()->for($owner)->create(['title' => 'Team note']);

    $this->actingAs($member)->getJson("/api/notes/{$note->id}")->assertOk();
    $this->actingAs($member)->getJson('/api/notes')->assertJsonPath('data.meta.total', 1);
    $this->actingAs($member)->putJson("/api/notes/{$note->id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/notes/{$note->id}")->assertForbidden();

    $this->actingAs($admin)->putJson("/api/notes/{$note->id}", ['title' => 'Edited by admin'])->assertOk();
    $this->actingAs($owner)->deleteJson("/api/notes/{$note->id}")->assertOk();
});

it('scopes subscriptions per workspace and limits billing changes to the owner', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $admin = addMember($tenant, User::factory()->create(), TenantRole::Admin);
    $member = addMember($tenant, User::factory()->create());
    $outsider = User::factory()->create();

    $this->actingAs($owner)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();
    expect(Subscription::withoutTenancy()->sole()->tenant_id)->toBe($tenant->id);

    // Everyone in the workspace can see it; nobody outside can.
    $this->actingAs($member)->getJson('/api/billing/subscription')->assertJsonPath('data.plan', 'starter');
    $this->actingAs($outsider)->getJson('/api/billing/subscription')->assertJsonPath('data', null);

    // Only the owner can change it.
    $this->actingAs($admin)->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertForbidden();
    $this->actingAs($member)->postJson('/api/billing/cancel')->assertForbidden();

    // Another workspace can buy independently, and cannot confirm this workspace's subscription.
    $reference = Subscription::withoutTenancy()->sole()->gateway_subscription_id;
    $this->actingAs($outsider)->postJson('/api/billing/confirm', ['razorpay_subscription_id' => $reference, 'razorpay_payment_id' => 'pay_x', 'razorpay_signature' => 'sig'])
        ->assertNotFound();
    $this->actingAs($outsider)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();
    expect(Subscription::withoutTenancy()->count())->toBe(2);
});

it('scopes support tickets per workspace: members see their own, admins see the workspace', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $member = addMember($tenant, User::factory()->create());
    $other = addMember($tenant, User::factory()->create());
    $outsider = User::factory()->create();

    foreach ([$member, $other, $outsider] as $u) {
        $this->actingAs($u)->postJson('/api/support/tickets', ['subject' => 'Help from '.$u->id, 'body' => 'Something is broken here.'])->assertCreated();
    }
    expect(SupportTicket::withoutTenancy()->whereNotNull('tenant_id')->count())->toBe(3);

    $this->actingAs($member)->getJson('/api/support/tickets')->assertJsonPath('data.meta.total', 1);
    $this->actingAs($owner)->getJson('/api/support/tickets')->assertJsonPath('data.meta.total', 2);
    $this->actingAs($outsider)->getJson('/api/support/tickets')->assertJsonPath('data.meta.total', 1);
});

it('keeps unlinked inbound support tickets outside every tenant', function () {
    $ticket = SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/support/tickets')->assertJsonPath('data.meta.total', 0);
    expect($ticket->fresh()->tenant_id)->toBeNull();
});
