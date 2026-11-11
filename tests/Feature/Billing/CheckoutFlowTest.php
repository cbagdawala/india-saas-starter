<?php

use App\Models\User;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Exceptions\GatewayNotConfiguredException;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Gateways\RazorpayGateway;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => spa());

it('binds the fake gateway by default', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeGateway::class);
});

it('lists the INR plans without exposing gateway plan ids', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/billing/plans')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'starter')
        ->assertJsonPath('data.0.amount', 49900)
        ->assertJsonPath('data.1.amount', 149900)
        ->assertJsonPath('data.1.currency', 'INR')
        ->assertJsonMissingPath('data.0.razorpay_plan_id');
});

it('requires auth for billing endpoints', function () {
    $this->getJson('/api/billing/plans')->assertUnauthorized();
    $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertUnauthorized();
});

it('runs the fake checkout flow: checkout, confirm, cancel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $checkout = $this->postJson('/api/billing/checkout', ['plan' => 'pro'])
        ->assertCreated()->assertJsonPath('data.driver', 'fake')->json('data');

    $this->getJson('/api/billing/subscription')->assertJsonPath('data.status', 'created')->assertJsonPath('data.plan', 'pro');

    $this->postJson('/api/billing/confirm', [
        'razorpay_subscription_id' => $checkout['reference'],
        'razorpay_payment_id' => 'pay_fake_1',
        'razorpay_signature' => $checkout['signature'],
    ])->assertOk()->assertJsonPath('data.status', 'active');

    // can't start a second subscription while one is live
    $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->assertStatus(409);

    $this->postJson('/api/billing/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(app(PaymentGateway::class)->cancelled)->toBe([$checkout['reference']])
        ->and(Subscription::withoutTenancy()->where('user_id', $user->id)->first()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('rejects a bad signature and an unknown plan', function () {
    $this->actingAs(User::factory()->create());
    $checkout = $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->json('data');

    $this->postJson('/api/billing/confirm', [
        'razorpay_subscription_id' => $checkout['reference'], 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged',
    ])->assertStatus(422);

    $this->getJson('/api/billing/subscription')->assertJsonPath('data.status', 'created');
    $this->postJson('/api/billing/checkout', ['plan' => 'nope'])->assertStatus(422);
});

it('cannot confirm someone else\'s subscription', function () {
    $this->actingAs(User::factory()->create());
    $checkout = $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->json('data');

    $this->actingAs(User::factory()->create())->postJson('/api/billing/confirm', [
        'razorpay_subscription_id' => $checkout['reference'], 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => FakeGateway::VALID_SIGNATURE,
    ])->assertNotFound();
});

function confirmPayload(array $checkout, string $payment = 'pay_fake_1'): array
{
    return ['razorpay_subscription_id' => $checkout['reference'], 'razorpay_payment_id' => $payment, 'razorpay_signature' => $checkout['signature']];
}

it('rejects replaying confirm after the subscription is active or cancelled', function () {
    $this->actingAs(User::factory()->create());
    $checkout = $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->json('data');

    $this->postJson('/api/billing/confirm', confirmPayload($checkout))->assertOk();
    $this->postJson('/api/billing/confirm', confirmPayload($checkout, 'pay_fake_2'))->assertStatus(409);

    $this->postJson('/api/billing/cancel')->assertOk();
    $this->postJson('/api/billing/confirm', confirmPayload($checkout))->assertStatus(409);
    $this->getJson('/api/billing/subscription')->assertJsonPath('data.status', 'cancelled');
});

it('rejects a payment id that was already recorded', function () {
    $this->actingAs(User::factory()->create());
    $first = $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->json('data');
    $this->postJson('/api/billing/confirm', confirmPayload($first, 'pay_dup'))->assertOk();
    $this->postJson('/api/billing/cancel')->assertOk();

    $second = $this->postJson('/api/billing/checkout', ['plan' => 'starter'])->json('data');
    $this->postJson('/api/billing/confirm', confirmPayload($second, 'pay_dup'))->assertStatus(409);
});

it('rejects a subscription created under a different gateway driver', function () {
    $user = User::factory()->create();
    $sub = Subscription::factory()->for($user)->create(['gateway' => 'razorpay']);

    $this->actingAs($user)->postJson('/api/billing/confirm', [
        'razorpay_subscription_id' => $sub->gateway_subscription_id, 'razorpay_payment_id' => 'pay_x', 'razorpay_signature' => FakeGateway::VALID_SIGNATURE,
    ])->assertStatus(409);
});

it('rejects weak passwords at registration', function () {
    foreach (['alllowercase1', 'NoDigitsHereAtAll', 'Short1Aa'] as $weak) {
        $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'w@example.com', 'password' => $weak, 'password_confirmation' => $weak])
            ->assertStatus(422)->assertJsonValidationErrorFor('password', 'errors');
    }
});

describe('RazorpayGateway', function () {
    it('verifyPayment: valid, tampered, missing and empty-secret cases', function () {
        config(['billing.razorpay.key_id' => 'rzp_test_abc', 'billing.razorpay.key_secret' => 'known-secret']);
        $gateway = new RazorpayGateway;
        $sub = ['razorpay_subscription_id' => 'sub_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => hash_hmac('sha256', 'pay_1|sub_1', 'known-secret')];
        $order = ['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => hash_hmac('sha256', 'order_1|pay_1', 'known-secret')];

        expect($gateway->verifyPayment($sub))->toBeTrue()
            ->and($gateway->verifyPayment($order))->toBeTrue()
            ->and($gateway->verifyPayment(['razorpay_payment_id' => 'pay_2'] + $sub))->toBeFalse()   // tampered payment id
            ->and($gateway->verifyPayment(['razorpay_subscription_id' => 'sub_2'] + $sub))->toBeFalse()
            ->and($gateway->verifyPayment(['razorpay_payment_id' => ''] + $sub))->toBeFalse()        // missing id
            ->and($gateway->verifyPayment(['razorpay_signature' => ''] + $sub))->toBeFalse()
            ->and($gateway->verifyPayment(['razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sub['razorpay_signature']]))->toBeFalse(); // no sub/order id

        config(['billing.razorpay.key_secret' => '']);
        expect(fn () => $gateway->verifyPayment($sub))->toThrow(GatewayNotConfiguredException::class);
    });

    it('refuses with a clear exception when keys are empty', function () {
        Http::fake();
        config(['billing.razorpay.key_id' => '', 'billing.razorpay.key_secret' => '']);
        $gateway = new RazorpayGateway;
        $user = User::factory()->create();

        expect(fn () => $gateway->createSubscription($user, ['key' => 'starter', 'razorpay_plan_id' => 'plan_1']))
            ->toThrow(GatewayNotConfiguredException::class, 'RAZORPAY_KEY_ID')
            ->and(fn () => $gateway->createOrder($user, 100))->toThrow(GatewayNotConfiguredException::class)
            ->and(fn () => $gateway->verifyPayment(['razorpay_payment_id' => 'p', 'razorpay_subscription_id' => 's', 'razorpay_signature' => 'x']))
            ->toThrow(GatewayNotConfiguredException::class, 'RAZORPAY_KEY_SECRET');

        Http::assertNothingSent();
    });

    it('creates a subscription against the Razorpay API with basic auth', function () {
        config(['billing.razorpay.key_id' => 'rzp_test_abc', 'billing.razorpay.key_secret' => 'shh']);
        Http::fake(['api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_real_1'])]);

        $checkout = (new RazorpayGateway)->createSubscription(User::factory()->create(), ['key' => 'starter', 'razorpay_plan_id' => 'plan_1']);

        expect($checkout->reference)->toBe('sub_real_1')->and($checkout->toArray()['key_id'])->toBe('rzp_test_abc');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/subscriptions'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_abc:shh'))
            && $r['plan_id'] === 'plan_1');
    });

    it('verifies the checkout signature with HMAC-SHA256', function () {
        config(['billing.razorpay.key_id' => 'rzp_test_abc', 'billing.razorpay.key_secret' => 'shh']);
        $sig = hash_hmac('sha256', 'pay_1|sub_1', 'shh');
        $gateway = new RazorpayGateway;

        expect($gateway->verifyPayment(['razorpay_payment_id' => 'pay_1', 'razorpay_subscription_id' => 'sub_1', 'razorpay_signature' => $sig]))->toBeTrue()
            ->and($gateway->verifyPayment(['razorpay_payment_id' => 'pay_1', 'razorpay_subscription_id' => 'sub_1', 'razorpay_signature' => 'bad']))->toBeFalse();
    });

    it('returns a 503 envelope through the API when selected but unconfigured', function () {
        config(['billing.driver' => 'razorpay', 'billing.razorpay.key_id' => '', 'billing.razorpay.key_secret' => '']);
        app()->forgetInstance(PaymentGateway::class);

        $this->actingAs(User::factory()->create())->postJson('/api/billing/checkout', ['plan' => 'starter'])
            ->assertStatus(503)->assertJsonPath('success', false);
    });
});
