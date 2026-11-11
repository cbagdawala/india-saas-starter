<?php

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\BillingWebhookEvent;
use App\Modules\Billing\Models\Subscription;

function chargedEvent(string $subscriptionId): array
{
    return [
        'entity' => 'event',
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => ['entity' => ['id' => $subscriptionId, 'status' => 'active', 'current_end' => 1900000000]],
            'payment' => ['entity' => ['id' => 'pay_123']],
        ],
    ];
}

it('rejects a missing or wrong signature', function () {
    $raw = json_encode(chargedEvent('sub_x'));

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(400);

    [$raw, $headers] = signedWebhook(chargedEvent('sub_x'), 'some-other-secret');
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature']], $raw)
        ->assertStatus(400)->assertJsonPath('success', false);

    expect(BillingWebhookEvent::count())->toBe(0);
});

it('refuses everything when the webhook secret is empty', function () {
    config(['billing.razorpay.webhook_secret' => '']);
    $raw = json_encode(chargedEvent('sub_x'));
    $sig = hash_hmac('sha256', $raw, '');

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $raw)->assertStatus(503);
});

it('verifies the signature over the raw body and activates the subscription', function () {
    $sub = Subscription::factory()->create(['gateway_subscription_id' => 'sub_abc']);
    [$raw, $headers] = signedWebhook(chargedEvent('sub_abc'));

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_1'], $raw)
        ->assertOk()->assertJsonPath('data.duplicate', false);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->last_payment_id)->toBe('pay_123')
        ->and($sub->current_period_end->timestamp)->toBe(1900000000);
});

it('is idempotent on the event id', function () {
    $sub = Subscription::factory()->create(['gateway_subscription_id' => 'sub_abc']);
    [$raw, $headers] = signedWebhook(chargedEvent('sub_abc'));
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_dup'];

    $this->call('POST', '/api/billing/webhook', [], [], [], $server, $raw)->assertOk()->assertJsonPath('data.duplicate', false);

    // A later cancel must not be undone by a replay of the earlier event.
    $sub->update(['status' => SubscriptionStatus::Cancelled]);

    $this->call('POST', '/api/billing/webhook', [], [], [], $server, $raw)->assertOk()->assertJsonPath('data.duplicate', true);

    expect(BillingWebhookEvent::where('event_id', 'evt_dup')->count())->toBe(1)
        ->and($sub->refresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('records events for unknown subscriptions without failing', function () {
    [$raw, $headers] = signedWebhook(chargedEvent('sub_unknown'));

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_u'], $raw)->assertOk();

    expect(BillingWebhookEvent::where('event_id', 'evt_u')->exists())->toBeTrue();
});

it('marks a subscription cancelled on subscription.cancelled', function () {
    $sub = Subscription::factory()->active()->create(['gateway_subscription_id' => 'sub_c']);
    $event = ['event' => 'subscription.cancelled', 'payload' => ['subscription' => ['entity' => ['id' => 'sub_c']]]];
    [$raw, $headers] = signedWebhook($event);

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_c'], $raw)->assertOk();

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Cancelled)->and($sub->cancelled_at)->not->toBeNull();
});

it('ignores late activate/charge events for terminal subscriptions but still records them', function (SubscriptionStatus $terminal) {
    $sub = Subscription::factory()->create(['gateway_subscription_id' => 'sub_t', 'status' => $terminal]);
    [$raw, $headers] = signedWebhook(chargedEvent('sub_t'));

    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_late_'.$terminal->value], $raw)
        ->assertOk()->assertJsonPath('data.duplicate', false);

    expect($sub->refresh()->status)->toBe($terminal)
        ->and($sub->last_payment_id)->toBeNull()
        ->and(BillingWebhookEvent::where('event_id', 'evt_late_'.$terminal->value)->whereNotNull('processed_at')->exists())->toBeTrue();
})->with([SubscriptionStatus::Cancelled, SubscriptionStatus::Completed]);

function timedEvent(string $name, string $subId, int $createdAt, string $payment = 'pay_t'): array
{
    return ['event' => $name, 'created_at' => $createdAt, 'payload' => [
        'subscription' => ['entity' => ['id' => $subId, 'status' => 'active']],
        'payment' => ['entity' => ['id' => $payment]],
    ]];
}

function post(object $test, array $event, string $eventId): void
{
    [$raw, $headers] = signedWebhook($event);
    $test->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId], $raw)->assertOk();
}

it('ignores events older than the replay window', function () {
    $sub = Subscription::factory()->create(['gateway_subscription_id' => 'sub_old']);

    post($this, timedEvent('subscription.activated', 'sub_old', now()->subHours(49)->timestamp), 'evt_old');

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Created)->and(BillingWebhookEvent::where('event_id', 'evt_old')->exists())->toBeTrue();

    post($this, timedEvent('subscription.activated', 'sub_old', now()->subHours(47)->timestamp), 'evt_ok');
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('does not let an older event undo a newer one that arrived first', function () {
    $sub = Subscription::factory()->active()->create(['gateway_subscription_id' => 'sub_ooo']);
    $t = now()->timestamp;

    post($this, timedEvent('subscription.halted', 'sub_ooo', $t - 60), 'evt_newer');       // delivered first, happened later
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::PastDue);

    post($this, timedEvent('subscription.charged', 'sub_ooo', $t - 600, 'pay_older'), 'evt_older'); // arrives late
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::PastDue)->and($sub->last_event_at->timestamp)->toBe($t - 60);

    post($this, timedEvent('subscription.charged', 'sub_ooo', $t - 30, 'pay_newest'), 'evt_newest');
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('still processes events that carry no timestamp', function () {
    $sub = Subscription::factory()->create(['gateway_subscription_id' => 'sub_nots']);
    post($this, chargedEvent('sub_nots'), 'evt_nots');

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
});
