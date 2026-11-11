<?php

namespace App\Modules\Billing\Gateways;

use App\Models\User;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Data\Checkout;
use Illuminate\Support\Str;

/** In-memory gateway for local dev and tests (BILLING_DRIVER=fake). Never talks to the network. */
class FakeGateway implements PaymentGateway
{
    public const VALID_SIGNATURE = 'fake_signature_ok';

    /** @var list<string> */
    public array $cancelled = [];

    public function driver(): string
    {
        return 'fake';
    }

    public function createSubscription(User $user, array $plan): Checkout
    {
        return new Checkout('fake', 'sub_fake_'.Str::lower(Str::random(14)), [
            'plan' => $plan['key'] ?? null,
            'amount' => $plan['amount'] ?? null,
            'currency' => $plan['currency'] ?? 'INR',
            'signature' => self::VALID_SIGNATURE,
        ]);
    }

    public function createOrder(User $user, int $amount, string $currency = 'INR', array $notes = []): Checkout
    {
        return new Checkout('fake', 'order_fake_'.Str::lower(Str::random(14)), [
            'amount' => $amount,
            'currency' => $currency,
            'signature' => self::VALID_SIGNATURE,
        ]);
    }

    public function verifyPayment(array $payload): bool
    {
        return hash_equals(self::VALID_SIGNATURE, (string) ($payload['razorpay_signature'] ?? ''));
    }

    public function cancel(string $reference): void
    {
        $this->cancelled[] = $reference;
    }
}
