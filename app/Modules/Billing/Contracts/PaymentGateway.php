<?php

namespace App\Modules\Billing\Contracts;

use App\Models\User;
use App\Modules\Billing\Data\Checkout;

interface PaymentGateway
{
    public function driver(): string;

    /**
     * Start a recurring subscription for a plan from config/billing.php.
     *
     * @param  array<string, mixed>  $plan  the plan config entry (+ 'key')
     */
    public function createSubscription(User $user, array $plan): Checkout;

    /** One-off order (amount in paise). */
    public function createOrder(User $user, int $amount, string $currency = 'INR', array $notes = []): Checkout;

    /**
     * Verify the signature the checkout handed back to the browser.
     *
     * @param  array<string, mixed>  $payload  razorpay_payment_id, razorpay_signature, razorpay_subscription_id|razorpay_order_id
     */
    public function verifyPayment(array $payload): bool;

    public function cancel(string $reference): void;
}
