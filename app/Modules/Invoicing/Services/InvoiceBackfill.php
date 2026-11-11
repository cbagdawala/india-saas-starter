<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Billing\Models\BillingWebhookEvent;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Support\SellerConfig;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds confirmed payments that never got an invoice and issues it. The auto-issue listener can fail (seller not
 * configured, database hiccup) and Razorpay redelivers the SAME event id, which the webhook ledger drops, so recovery
 * cannot depend on a redelivery. Sources: (1) each subscription's last_payment_id, (2) subscription.activated /
 * subscription.charged rows in the webhook ledger from the last `invoicing.backfill_days` days (renewals).
 * Idempotent: issueForPayment is unique per payment id, so reruns and overlapping runs never double-issue.
 */
class InvoiceBackfill
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** @return array{issued: int, failed: int, skipped: ?string} */
    public function run(): array
    {
        $result = ['issued' => 0, 'failed' => 0, 'skipped' => null];

        if (! config('invoicing.enabled')) {
            return ['skipped' => 'invoicing is disabled'] + $result;
        }
        if (($problems = SellerConfig::problems()) !== []) {
            Log::warning('Invoice backfill skipped: invoicing is not configured.', ['problems' => $problems]);

            return ['skipped' => implode('; ', $problems)] + $result;
        }

        Subscription::withoutTenancy()
            ->whereNotNull('last_payment_id')->where('last_payment_id', '!=', '')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('invoices')->whereColumn('invoices.payment_id', 'subscriptions.last_payment_id')->where('invoices.type', Invoice::TYPE_INVOICE))
            ->chunkById(100, function ($subscriptions) use (&$result) {
                foreach ($subscriptions as $subscription) {
                    $this->issue($subscription, (string) $subscription->last_payment_id, $this->chargedAmount((string) $subscription->last_payment_id) ?? (int) $subscription->amount, $result);
                }
            });

        BillingWebhookEvent::query()
            ->whereIn('type', ['subscription.activated', 'subscription.charged'])->whereNotNull('processed_at')
            ->where('created_at', '>=', now()->subDays((int) config('invoicing.backfill_days')))
            ->chunkById(100, function ($events) use (&$result) {
                foreach ($events as $event) {
                    $payload = (array) $event->payload;
                    $paymentId = (string) ($payload['payload']['payment']['entity']['id'] ?? '');
                    $gatewayId = (string) ($payload['payload']['subscription']['entity']['id'] ?? '');
                    if ($paymentId === '' || $gatewayId === '' || Invoice::withoutTenancy()->where('type', Invoice::TYPE_INVOICE)->where('payment_id', $paymentId)->exists()) {
                        continue;
                    }
                    $subscription = Subscription::withoutTenancy()->where('gateway_subscription_id', $gatewayId)->first();
                    if ($subscription) {
                        $amount = (int) ($payload['payload']['payment']['entity']['amount'] ?? $subscription->amount);
                        $this->issue($subscription, $paymentId, $amount, $result);
                    }
                }
            });

        return $result;
    }

    /**
     * What the gateway actually charged for this payment, from the ledger (webhook payload or the browser-confirm row), so a
     * renewal at a changed price or a plan change is invoiced at the real amount. The plan amount is only the fallback.
     */
    private function chargedAmount(string $paymentId): ?int
    {
        $amount = BillingWebhookEvent::query()->where('payload->payload->payment->entity->id', $paymentId)->latest('id')->get()
            ->map(fn ($e) => (int) ($e->payload['payload']['payment']['entity']['amount'] ?? 0))->first(fn (int $a) => $a > 0);

        return $amount ?: null;
    }

    /** @param array{issued: int, failed: int, skipped: ?string} $result */
    private function issue(Subscription $subscription, string $paymentId, int $amount, array &$result): void
    {
        if ($amount < 1 || ! InvoiceService::gatewayAllowed((string) $subscription->gateway)) {
            return;
        }

        try {
            $tenant = Tenant::query()->find($subscription->tenant_id);
            if (! $tenant) {
                return;
            }
            $exists = Invoice::withoutTenancy()->where('type', Invoice::TYPE_INVOICE)->where('payment_id', $paymentId)->exists();
            $this->invoices->issueForPayment($tenant, $paymentId, $amount, InvoiceService::describeSubscription($subscription->plan, (string) config("billing.plans.{$subscription->plan}.interval", 'month')), $subscription->id);
            if (! $exists) {
                $result['issued']++;
            }
        } catch (Throwable $e) {
            $result['failed']++;
            report($e);
        }
    }
}
