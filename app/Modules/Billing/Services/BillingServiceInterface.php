<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Data\Checkout;
use App\Modules\Billing\Models\Subscription;

interface BillingServiceInterface
{
    /** @return array<string, array<string, mixed>> */
    public function plans(): array;

    public function currentSubscription(User $user): ?Subscription;

    /** Creates a pending subscription at the gateway and locally. */
    public function checkout(User $user, string $planKey): Checkout;

    /** Verifies the checkout signature and activates the subscription. */
    public function confirm(User $user, array $payload): Subscription;

    public function cancel(User $user): Subscription;

    /**
     * Handle a verified webhook delivery exactly once per event id.
     *
     * @return bool false when the event id was already processed
     */
    public function handleWebhook(string $eventId, array $event): bool;
}
