<?php

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Invoicing\Models\Invoice;

beforeEach(function () {
    spa();
    configureSeller();
});

function activateViaConfirm(object $test, User $user, string $paymentId = 'pay_fake_1'): array
{
    $checkout = $test->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated()->json('data');
    $test->postJson('/api/billing/confirm', [
        'razorpay_subscription_id' => $checkout['reference'], 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $checkout['signature'],
    ])->assertOk();

    return $checkout;
}

function sendWebhook(object $test, array $event, string $eventId): void
{
    [$raw, $headers] = signedWebhook($event);
    $test->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $headers['X-Razorpay-Signature'], 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId], $raw)->assertOk();
}

function subEvent(string $name, string $subId, string $paymentId, ?int $amount = null): array
{
    return ['event' => $name, 'payload' => [
        'subscription' => ['entity' => ['id' => $subId, 'status' => 'active']],
        'payment' => ['entity' => ['id' => $paymentId] + ($amount ? ['amount' => $amount] : [])],
    ]];
}

it('issues one tax invoice when a payment is confirmed, for the workspace', function () {
    $user = User::factory()->create();
    setBillingProfile($user->currentTenant, ['state_code' => '29']);

    activateViaConfirm($this, $user);

    $invoice = Invoice::withoutTenancy()->sole();
    expect($invoice->tenant_id)->toBe($user->current_tenant_id)
        ->and($invoice->number)->toBe('INV/26-27/00001')
        ->and($invoice->payment_id)->toBe('pay_fake_1')->and($invoice->payment_method)->toBe('gateway')->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->total)->toBe(49900)->and($invoice->igst)->toBe(7612)->and($invoice->subscription_id)->toBe(Subscription::withoutTenancy()->sole()->id)
        ->and($invoice->lines()->sole()->description)->toBe('Starter plan subscription (per month)');
});

it('does not issue a second invoice for the same payment when the webhook repeats it', function () {
    $user = User::factory()->create();
    $checkout = activateViaConfirm($this, $user, 'pay_dup');

    sendWebhook($this, subEvent('subscription.activated', $checkout['reference'], 'pay_dup'), 'evt_a');
    sendWebhook($this, subEvent('subscription.charged', $checkout['reference'], 'pay_dup', 49900), 'evt_b');

    expect(Invoice::withoutTenancy()->count())->toBe(1);
});

it('issues an invoice for each renewal charge, using the charged amount', function () {
    $user = User::factory()->create();
    $checkout = activateViaConfirm($this, $user, 'pay_1');

    sendWebhook($this, subEvent('subscription.charged', $checkout['reference'], 'pay_2', 49900), 'evt_r');
    sendWebhook($this, subEvent('subscription.charged', $checkout['reference'], 'pay_2', 49900), 'evt_r2'); // new event id, same payment

    expect(Invoice::withoutTenancy()->orderBy('id')->pluck('number', 'payment_id')->all())->toBe(['pay_1' => 'INV/26-27/00001', 'pay_2' => 'INV/26-27/00002']);
});

it('never lets a failed invoice break the payment, and issues it later on retry', function () {
    configureSeller(['gstin' => null]); // not configured
    $user = User::factory()->create();
    $checkout = activateViaConfirm($this, $user, 'pay_late');

    expect(Subscription::withoutTenancy()->sole()->status->value)->toBe('active')->and(Invoice::withoutTenancy()->count())->toBe(0);

    configureSeller();
    sendWebhook($this, subEvent('subscription.charged', $checkout['reference'], 'pay_late', 49900), 'evt_retry');

    expect(Invoice::withoutTenancy()->sole()->payment_id)->toBe('pay_late');
});

it('ignores events without a payment', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertCreated();

    expect(Invoice::withoutTenancy()->count())->toBe(0);
});
