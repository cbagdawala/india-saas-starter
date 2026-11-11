<?php

use App\Models\User;
use App\Modules\Auth\Exceptions\AccountErasureBlocked;
use App\Modules\Auth\Services\AccountService;
use App\Modules\Billing\Models\BillingWebhookEvent;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Notes\Models\Note;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    spa();
    configureSeller();
});

it('exports the personal data held about the signed-in user as JSON', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $other = User::factory()->create();
    Note::factory()->for($user)->create(['title' => 'Mine']);
    Note::factory()->for($other)->create(['title' => 'Not mine']);
    SupportTicket::factory()->create(['user_id' => $user->id, 'tenant_id' => $user->current_tenant_id, 'subject' => 'My ticket']);
    SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null, 'from_email' => 'me@example.com', 'sender_verified' => true, 'subject' => 'Unlinked inbound']);

    $json = $this->actingAs($user)->getJson('/api/account/export')->assertOk()->json();

    expect($json['user']['email'])->toBe('me@example.com')->and($json['user'])->not->toHaveKeys(['password', 'remember_token'])
        ->and(collect($json['notes'])->pluck('title')->all())->toBe(['Mine'])
        ->and(collect($json['support_tickets'])->pluck('subject')->sort()->values()->all())->toBe(['My ticket', 'Unlinked inbound'])
        ->and($json['workspaces'][0]['role'])->toBe('owner');
    $this->getJson('/api/account/export')->assertOk();
    expect(fn () => 1)->not->toThrow(Exception::class);
});

it('requires the password to delete the account and is throttled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/account', [])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->actingAs($user)->deleteJson('/api/account', ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(User::find($user->id))->not->toBeNull();
});

it('deletes the account, its data and a workspace it alone owned, but keeps issued invoices', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $tenant = $user->currentTenant;
    Note::factory()->for($user)->create();
    SupportTicket::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);
    SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null, 'from_email' => 'gone@example.com', 'sender_verified' => true]);
    $invoice = app(InvoiceService::class)->issue($tenant, [['description' => 'Plan', 'quantity' => 1, 'unit_price' => 49900]], ['payment_method' => 'manual']);
    $user->createToken('t');

    $this->actingAs($user)->deleteJson('/api/account', ['password' => 'password'])->assertOk();

    expect(User::find($user->id))->toBeNull()
        ->and(Note::withoutTenancy()->count())->toBe(0)
        ->and(SupportTicket::withoutTenancy()->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->count())->toBe(0);

    // the workspace is scrubbed and soft-deleted; the invoice (a legal record) survives with its buyer snapshot
    $scrubbed = Tenant::withTrashed()->find($tenant->id);
    expect($scrubbed->trashed())->toBeTrue()->and($scrubbed->name)->toBe('Deleted workspace')->and($scrubbed->legal_name)->toBeNull()->and($scrubbed->gstin)->toBeNull();
    expect(Invoice::withoutTenancy()->find($invoice->id))->not->toBeNull()->and(Invoice::withoutTenancy()->find($invoice->id)->buyer['legal_name'])->not->toBeNull();
});

it('refuses deletion for the last owner of a workspace with other members or an active subscription', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $member = addMember($tenant, User::factory()->create(), TenantRole::Member, false);

    $this->actingAs($owner)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(409)->assertJsonPath('errors.account.0', fn ($m) => str_contains($m, 'only owner'));
    expect(User::find($owner->id))->not->toBeNull();

    // with another owner it goes through, and the workspace stays for them
    $tenant->users()->updateExistingPivot($member->id, ['role' => 'owner']);
    $this->actingAs($owner)->deleteJson('/api/account', ['password' => 'password'])->assertOk();
    expect(Tenant::find($tenant->id))->not->toBeNull()->and($member->fresh()->roleIn($tenant))->toBe(TenantRole::Owner);
});

it('refuses deletion while the workspace has an active subscription', function () {
    $solo = User::factory()->create();
    Subscription::factory()->active()->create(['user_id' => $solo->id, 'tenant_id' => $solo->current_tenant_id]);
    $this->actingAs($solo)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(409)->assertJsonPath('errors.account.0', fn ($m) => str_contains($m, 'active subscription'));
});

it('lets a plain member delete their account and leaves the workspace intact', function () {
    $owner = User::factory()->create();
    $member = addMember($owner->currentTenant, User::factory()->create(), TenantRole::Member);

    $this->actingAs($member)->deleteJson('/api/account', ['password' => 'password'])->assertOk();

    expect(Tenant::find($owner->current_tenant_id))->not->toBeNull()->and($owner->currentTenant->users()->count())->toBe(1);
});

it('purges accounts that stayed unverified, but nothing else', function () {
    $stale = User::factory()->unverified()->create(['created_at' => now()->subDays(20)]);
    $fresh = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
    $verifiedOld = User::factory()->create(['created_at' => now()->subDays(400)]);

    $this->artisan('accounts:purge-unverified')->expectsOutputToContain('Purged 1 unverified')->assertSuccessful();

    expect(User::find($stale->id))->toBeNull()->and(User::find($fresh->id))->not->toBeNull()->and(User::find($verifiedOld->id))->not->toBeNull();

    config(['accounts.unverified_purge_days' => 2]);
    $this->artisan('accounts:purge-unverified')->assertSuccessful();
    expect(User::find($fresh->id))->toBeNull();
});

it('strips old webhook payloads but keeps the idempotency rows', function () {
    BillingWebhookEvent::create(['event_id' => 'old', 'type' => 'subscription.charged', 'payload' => ['secret' => 'customer data'], 'processed_at' => now()]);
    DB::table('billing_webhook_events')->where('event_id', 'old')->update(['created_at' => now()->subDays(91)]);
    BillingWebhookEvent::create(['event_id' => 'new', 'type' => 'subscription.charged', 'payload' => ['keep' => 'me'], 'processed_at' => now()]);

    $this->artisan('billing:prune-webhook-payloads')->expectsOutputToContain('Stripped 1')->assertSuccessful();

    expect(BillingWebhookEvent::where('event_id', 'old')->first()->payload)->toBe(['pruned' => true])->and(BillingWebhookEvent::where('event_id', 'new')->first()->payload)->toBe(['keep' => 'me']);
    $this->artisan('billing:prune-webhook-payloads')->expectsOutputToContain('Stripped 0')->assertSuccessful();
});

it('schedules the purge and prune commands daily', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression)->implode("\n");

    expect($commands)->toContain('accounts:purge-unverified')->toContain('billing:prune-webhook-payloads');
});

it('keeps the workspace subscription when the purchaser is deleted and a co-owner remains', function () {
    $purchaser = User::factory()->create();
    $tenant = $purchaser->currentTenant;
    $coOwner = addMember($tenant, User::factory()->create(), TenantRole::Owner, false);
    $subscription = Subscription::factory()->active()->create(['user_id' => $purchaser->id, 'tenant_id' => $tenant->id, 'gateway_subscription_id' => 'sub_keep']);
    Note::factory()->for($purchaser)->create(['title' => 'Shared note']);

    $this->actingAs($purchaser)->deleteJson('/api/account', ['password' => 'password'])->assertOk();

    $kept = Subscription::withoutTenancy()->find($subscription->id);
    expect($kept)->not->toBeNull()->and($kept->user_id)->toBe($coOwner->id)->and($kept->status->value)->toBe('active');
    // the note stays in the workspace, unattributed
    expect(Note::withoutTenancy()->where('title', 'Shared note')->sole()->user_id)->toBeNull();

    // webhooks still apply to it and invoices still issue for the workspace
    [$raw, $headers] = signedWebhook(['event' => 'subscription.charged', 'payload' => ['subscription' => ['entity' => ['id' => 'sub_keep', 'status' => 'active']], 'payment' => ['entity' => ['id' => 'pay_after', 'amount' => 49900]]]]);
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_after'], $raw)->assertOk();
    $this->artisan('invoicing:backfill')->assertSuccessful();

    expect(Subscription::withoutTenancy()->find($subscription->id)->last_payment_id)->toBe('pay_after')
        ->and(Invoice::withoutTenancy()->where('payment_id', 'pay_after')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('keeps the subscription row (unattributed) even when no owner can inherit it', function () {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->create(['user_id' => $user->id, 'tenant_id' => $user->current_tenant_id, 'status' => 'cancelled']);

    $this->actingAs($user)->deleteJson('/api/account', ['password' => 'password'])->assertOk();

    expect(Subscription::withoutTenancy()->find($subscription->id)->user_id)->toBeNull();
});

it('checks the blockers again inside the erase transaction', function () {
    $owner = User::factory()->create();
    $tenant = $owner->currentTenant;
    $accounts = app(AccountService::class);
    expect($accounts->blockers($owner))->toBe([]); // looked fine ...

    addMember($tenant, User::factory()->create(), TenantRole::Member, false); // ... then someone joined before the delete ran
    expect(fn () => $accounts->erase($owner))->toThrow(AccountErasureBlocked::class);
    expect(User::find($owner->id))->not->toBeNull();
});

it('does not let an unverified squatter read or delete a victim inbound support mail', function () {
    $victimTicket = SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null, 'from_email' => 'victim@example.com', 'sender_verified' => true, 'subject' => 'Private billing question']);
    $squatter = User::factory()->unverified()->create(['email' => 'victim@example.com']);

    $export = $this->actingAs($squatter)->getJson('/api/account/export');
    $export->assertForbidden(); // export needs a verified email

    // even if reached through the service (e.g. an older client), the address match needs a verified email
    $data = app(AccountService::class)->export($squatter);
    expect(collect($data['support_tickets'])->pluck('subject')->all())->toBe([]);

    $this->actingAs($squatter)->deleteJson('/api/account', ['password' => 'password'])->assertForbidden();
    $squatter->forceFill(['created_at' => now()->subDays(30)])->save();
    $this->artisan('accounts:purge-unverified')->assertSuccessful();

    expect(User::find($squatter->id))->toBeNull()->and(SupportTicket::withoutTenancy()->find($victimTicket->id))->not->toBeNull();
});

it('matches inbound mail by address only for a verified account and a verified sender', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null, 'from_email' => 'me@example.com', 'sender_verified' => true, 'subject' => 'Verified sender']);
    SupportTicket::factory()->create(['user_id' => null, 'tenant_id' => null, 'from_email' => 'me@example.com', 'sender_verified' => false, 'subject' => 'Spoofed sender']);

    $subjects = collect($this->actingAs($user)->getJson('/api/account/export')->json('support_tickets'))->pluck('subject')->all();

    expect($subjects)->toBe(['Verified sender']);
});

it('exports notes only from workspaces the user still belongs to', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $shared = $other->currentTenant;
    addMember($shared, $user, TenantRole::Member, false);
    Note::factory()->for($user)->create(['title' => 'In my workspace']);
    app(TenantContext::class)->runAs($shared, fn () => Note::create(['title' => 'In shared'])->forceFill(['user_id' => $user->id])->save());
    $shared->users()->detach($user->id); // removed from the shared workspace

    $titles = collect($this->actingAs($user)->getJson('/api/account/export')->json('notes'))->pluck('title')->all();

    expect($titles)->toBe(['In my workspace']);
});
