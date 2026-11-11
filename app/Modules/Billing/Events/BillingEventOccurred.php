<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A billing state change worth reacting to (hook your own listener: emails, analytics, CRM). Fired inside the billing transaction; `data` is PII-free
 * (local ids, gateway ids, plan, amount in minor units, currency).
 *
 * Types: subscription.created | subscription.activated | subscription.charged | subscription.past_due |
 *        subscription.cancelled | subscription.completed | payment.failed | refund.requested | refund.processed
 */
class BillingEventOccurred
{
    use Dispatchable;

    /** @param array<string, scalar|null> $data */
    public function __construct(public readonly string $type, public readonly array $data) {}
}
