<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Data\Checkout;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Events\BillingEventOccurred;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\BillingWebhookEvent;
use App\Modules\Billing\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BillingService implements BillingServiceInterface
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function plans(): array
    {
        $plans = [];
        foreach ((array) config('billing.plans') as $key => $plan) {
            // razorpay_plan_id is internal; never sent to the SPA.
            unset($plan['razorpay_plan_id']);
            $plans[$key] = ['key' => $key] + $plan;
        }

        return $plans;
    }

    public function currentSubscription(User $user): ?Subscription
    {
        // Billing is per workspace: Subscription is tenant-scoped, so this is the current workspace's latest subscription.
        return Subscription::query()->latest('id')->first();
    }

    public function checkout(User $user, string $planKey): Checkout
    {
        $plan = config("billing.plans.{$planKey}") ?? throw new BillingException('Unknown plan.', 422);

        if ($this->currentSubscription($user)?->status->isLive()) {
            throw new BillingException('You already have an active subscription. Cancel it before changing plans.', 409);
        }

        $checkout = $this->gateway->createSubscription($user, ['key' => $planKey] + $plan);

        DB::transaction(function () use ($user, $planKey, $plan, $checkout) {
            $subscription = Subscription::create([
                'user_id' => $user->id, // the member who purchased; tenant_id is filled from the current workspace
                'plan' => $planKey,
                'status' => SubscriptionStatus::Created,
                'gateway' => $this->gateway->driver(),
                'gateway_subscription_id' => $checkout->reference,
                'amount' => $plan['amount'],
                'currency' => $plan['currency'],
            ]);
            $this->emit('subscription.created', $subscription);
        });

        return $checkout;
    }

    public function confirm(User $user, array $payload): Subscription
    {
        return DB::transaction(function () use ($payload) {
            $subscription = Subscription::query()
                ->where('gateway_subscription_id', (string) ($payload['razorpay_subscription_id'] ?? ''))
                ->lockForUpdate()
                ->first() ?? throw new BillingException('Subscription not found.', 404);

            // Only a freshly created subscription can be activated by a browser confirmation; cancelled/active ones cannot be replayed.
            if ($subscription->status !== SubscriptionStatus::Created) {
                throw new BillingException('This subscription can no longer be confirmed.', 409);
            }
            if ($subscription->gateway !== $this->gateway->driver()) {
                throw new BillingException('Subscription belongs to a different payment gateway.', 409);
            }

            $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
            if ($paymentId !== '' && Subscription::withoutTenancy()->where('last_payment_id', $paymentId)->exists()) {
                throw new BillingException('This payment has already been recorded.', 409);
            }

            if (! $this->gateway->verifyPayment($payload)) {
                throw new BillingException('Payment signature could not be verified.', 422);
            }

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'last_payment_id' => $paymentId,
                'current_period_end' => $subscription->current_period_end ?? now()->addMonth(),
            ]);
            // Same ledger the webhooks use, so every confirmed payment (not just the latest, which last_payment_id keeps)
            // can be found again by invoicing:backfill. event_id is unique, so this is idempotent per payment.
            if ($paymentId !== '') {
                BillingWebhookEvent::query()->firstOrCreate(['event_id' => 'confirm:'.$paymentId], [
                    'type' => 'subscription.activated',
                    'payload' => ['event' => 'subscription.activated', 'source' => 'browser-confirm', 'payload' => [
                        'subscription' => ['entity' => ['id' => $subscription->gateway_subscription_id]],
                        'payment' => ['entity' => ['id' => $paymentId, 'amount' => $subscription->amount]],
                    ]],
                    'processed_at' => now(),
                ]);
            }
            $this->emit('subscription.activated', $subscription, ['gateway_payment_id' => $paymentId ?: null]);

            return $subscription->refresh();
        });
    }

    public function cancel(User $user): Subscription
    {
        $subscription = $this->currentSubscription($user);
        if (! $subscription || ! $subscription->status->isLive()) {
            throw new BillingException('No active subscription to cancel.', 404);
        }

        $this->gateway->cancel($subscription->gateway_subscription_id);
        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
            $this->emit('subscription.cancelled', $subscription, ['cancelled_by' => 'customer']);
        });

        return $subscription->refresh();
    }

    public function handleWebhook(string $eventId, array $event): bool
    {
        try {
            return DB::transaction(function () use ($eventId, $event) {
                // The unique index on event_id is the idempotency guard; a duplicate aborts here.
                $record = BillingWebhookEvent::create([
                    'event_id' => $eventId,
                    'type' => (string) ($event['event'] ?? 'unknown'),
                    'payload' => $event,
                ]);

                $this->apply($event);
                $record->update(['processed_at' => now()]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** @param array<string, mixed> $event */
    private function apply(array $event): void
    {
        // Razorpay stamps events with created_at (unix). Ignore events older than the configured window (a replayed capture)
        // and, below, events older than the last one already applied to the subscription (out-of-order delivery).
        $at = isset($event['created_at']) && is_numeric($event['created_at']) ? (int) $event['created_at'] : null;
        $maxAgeHours = (int) config('billing.webhook_max_age_hours', 48);
        if ($at !== null && $maxAgeHours > 0 && now()->timestamp - $at > $maxAgeHours * 3600) {
            return;
        }

        $this->forwardPaymentEvent($event);

        $entity = $event['payload']['subscription']['entity'] ?? null;
        if (! is_array($entity) || empty($entity['id'])) {
            return; // payment-only or unrelated events are recorded but need no state change
        }

        // Webhooks have no tenant context; the gateway subscription id is globally unique and carries the tenant on the row.
        $subscription = Subscription::withoutTenancy()->where('gateway_subscription_id', $entity['id'])->lockForUpdate()->first();
        if (! $subscription) {
            return;
        }

        if ($at !== null && $subscription->last_event_at && $at < $subscription->last_event_at->timestamp) {
            return; // an older event arriving late must not undo a newer state
        }

        // Cancelled / completed are terminal: late or replayed activate/charge events are recorded but never resurrect them.
        if (in_array($subscription->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Completed], true)) {
            return;
        }

        $status = match ($event['event'] ?? '') {
            'subscription.activated', 'subscription.charged', 'subscription.resumed' => SubscriptionStatus::Active,
            'subscription.pending', 'subscription.halted' => SubscriptionStatus::PastDue,
            'subscription.cancelled' => SubscriptionStatus::Cancelled,
            'subscription.completed' => SubscriptionStatus::Completed,
            default => null,
        };
        if (! $status) {
            return;
        }

        $attributes = ['status' => $status];
        if ($at !== null) {
            $attributes['last_event_at'] = CarbonImmutable::createFromTimestampUTC($at);
        }
        if (! empty($entity['current_end'])) {
            $attributes['current_period_end'] = CarbonImmutable::createFromTimestampUTC((int) $entity['current_end']);
        }
        if ($paymentId = $event['payload']['payment']['entity']['id'] ?? null) {
            $attributes['last_payment_id'] = $paymentId;
        }
        if ($status === SubscriptionStatus::Cancelled) {
            $attributes['cancelled_at'] = now();
        }

        $subscription->update($attributes);

        $type = match ($event['event'] ?? '') {
            'subscription.activated', 'subscription.resumed' => 'subscription.activated',
            'subscription.charged' => 'subscription.charged',
            'subscription.pending', 'subscription.halted' => 'subscription.past_due',
            'subscription.cancelled' => 'subscription.cancelled',
            'subscription.completed' => 'subscription.completed',
            default => null,
        };
        if ($type) {
            $payment = $event['payload']['payment']['entity'] ?? [];
            $this->emit($type, $subscription, [
                'gateway_payment_id' => is_array($payment) && isset($payment['id']) ? (string) $payment['id'] : null,
                'charged_amount' => $type === 'subscription.charged' && is_array($payment) && isset($payment['amount']) ? (int) $payment['amount'] : null,
                'cancelled_by' => $type === 'subscription.cancelled' ? 'gateway' : null,
            ]);
        }
    }

    /**
     * payment.failed and refund.* do not change local state; they only fire BillingEventOccurred for your listeners.
     *
     * @param  array<string, mixed>  $event
     */
    private function forwardPaymentEvent(array $event): void
    {
        $name = (string) ($event['event'] ?? '');

        if ($name === 'payment.failed') {
            $payment = $event['payload']['payment']['entity'] ?? [];
            if (! is_array($payment)) {
                return;
            }
            $subscription = ! empty($payment['subscription_id'])
                ? Subscription::withoutTenancy()->where('gateway_subscription_id', (string) $payment['subscription_id'])->first()
                : null;

            BillingEventOccurred::dispatch('payment.failed', [
                'gateway' => $this->gateway->driver(),
                'gateway_payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
                'gateway_subscription_id' => isset($payment['subscription_id']) ? (string) $payment['subscription_id'] : null,
                'subscription_id' => $subscription?->id,
                'user_id' => $subscription?->user_id,
                'tenant_id' => $subscription?->tenant_id,
                'plan' => $subscription?->plan,
                'amount' => isset($payment['amount']) ? (int) $payment['amount'] : null,
                'currency' => isset($payment['currency']) ? (string) $payment['currency'] : null,
                'error_code' => isset($payment['error_code']) ? (string) $payment['error_code'] : null,
            ]);

            return;
        }

        $type = match ($name) {
            'refund.created' => 'refund.requested',
            'refund.processed' => 'refund.processed',
            default => null,
        };
        $refund = $event['payload']['refund']['entity'] ?? null;
        if ($type && is_array($refund)) {
            BillingEventOccurred::dispatch($type, [
                'gateway' => $this->gateway->driver(),
                'gateway_refund_id' => isset($refund['id']) ? (string) $refund['id'] : null,
                'gateway_payment_id' => isset($refund['payment_id']) ? (string) $refund['payment_id'] : null,
                'amount' => isset($refund['amount']) ? (int) $refund['amount'] : null,
                'currency' => isset($refund['currency']) ? (string) $refund['currency'] : null,
            ]);
        }
    }

    /**
     * subscription.* events: always the full EventSchema::SUBSCRIPTION_KEYS set; extras (gateway_payment_id,
     * charged_amount, cancelled_by) are explicit nulls when they do not apply.
     *
     * @param  array<string, scalar|null>  $extra
     */
    private function emit(string $type, Subscription $subscription, array $extra = []): void
    {
        BillingEventOccurred::dispatch($type, array_merge([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'tenant_id' => $subscription->tenant_id,
            'plan' => $subscription->plan,
            'interval' => (string) config("billing.plans.{$subscription->plan}.interval", 'month'),
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => $subscription->status->value,
            'gateway' => $subscription->gateway,
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'gateway_payment_id' => null,
            'charged_amount' => null,
            'cancelled_by' => null,
        ], $extra));
    }
}
