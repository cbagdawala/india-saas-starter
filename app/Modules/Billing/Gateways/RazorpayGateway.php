<?php

namespace App\Modules\Billing\Gateways;

use App\Models\User;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Data\Checkout;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\GatewayNotConfiguredException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay REST API (test mode with rzp_test_* keys). Keys come from env only; with empty keys every call
 * refuses with GatewayNotConfiguredException before any network access.
 */
class RazorpayGateway implements PaymentGateway
{
    public function driver(): string
    {
        return 'razorpay';
    }

    public function createSubscription(User $user, array $plan): Checkout
    {
        $planId = $plan['razorpay_plan_id'] ?? null;
        if (! $planId) {
            throw GatewayNotConfiguredException::missing('RAZORPAY_PLAN_'.strtoupper((string) ($plan['key'] ?? 'PLAN')));
        }

        $res = $this->post('/subscriptions', [
            'plan_id' => $planId,
            'total_count' => (int) config('billing.razorpay.total_count'),
            'customer_notify' => 1,
            'notes' => ['user_id' => (string) $user->id, 'plan' => (string) ($plan['key'] ?? '')],
        ]);

        return new Checkout('razorpay', $res['id'], ['key_id' => $this->keyId(), 'subscription_id' => $res['id']]);
    }

    public function createOrder(User $user, int $amount, string $currency = 'INR', array $notes = []): Checkout
    {
        $res = $this->post('/orders', [
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => 'u'.$user->id.'-'.now()->timestamp,
            'notes' => $notes + ['user_id' => (string) $user->id],
        ]);

        return new Checkout('razorpay', $res['id'], ['key_id' => $this->keyId(), 'order_id' => $res['id'], 'amount' => $amount, 'currency' => $currency]);
    }

    /** Checkout signature: HMAC-SHA256("<payment_id>|<subscription_id>" or "<order_id>|<payment_id>", key_secret). */
    public function verifyPayment(array $payload): bool
    {
        $secret = $this->secret();
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');

        if (! empty($payload['razorpay_subscription_id'])) {
            $message = $paymentId.'|'.$payload['razorpay_subscription_id'];
        } elseif (! empty($payload['razorpay_order_id'])) {
            $message = $payload['razorpay_order_id'].'|'.$paymentId;
        } else {
            return false;
        }

        if ($paymentId === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $message, $secret), $signature);
    }

    public function cancel(string $reference): void
    {
        $this->post("/subscriptions/{$reference}/cancel", ['cancel_at_cycle_end' => 0]);
    }

    private function keyId(): string
    {
        $key = (string) config('billing.razorpay.key_id');
        if ($key === '') {
            throw GatewayNotConfiguredException::missing('RAZORPAY_KEY_ID');
        }

        return $key;
    }

    private function secret(): string
    {
        $secret = (string) config('billing.razorpay.key_secret');
        if ($secret === '') {
            throw GatewayNotConfiguredException::missing('RAZORPAY_KEY_SECRET');
        }

        return $secret;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('billing.razorpay.base_url'))
            ->withBasicAuth($this->keyId(), $this->secret())
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        $client = $this->client(); // throws when unconfigured, before any request

        try {
            return $client->post($path, $body)->throw()->json();
        } catch (RequestException $e) {
            $message = $e->response->json('error.description') ?? 'Razorpay request failed';

            throw new BillingException("Razorpay: {$message}", 502);
        }
    }
}
