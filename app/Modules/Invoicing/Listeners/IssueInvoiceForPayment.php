<?php

namespace App\Modules\Invoicing\Listeners;

use App\Modules\Billing\Events\BillingEventOccurred;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * Issues the GST invoice once a subscription payment is confirmed (browser confirm or webhook). Runs after the billing
 * transaction commits, so a failure here can never undo or block a payment; it is reported and `invoicing:backfill`
 * (scheduled every 15 minutes) issues the missing invoice later. A redelivered webhook with the SAME event id is
 * dropped by the ledger before apply(), so it cannot be relied on for recovery. Idempotent per gateway payment id.
 */
class IssueInvoiceForPayment implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function handle(BillingEventOccurred $event): void
    {
        if (! config('invoicing.enabled') || ! in_array($event->type, ['subscription.activated', 'subscription.charged'], true)) {
            return;
        }

        $paymentId = (string) ($event->data['gateway_payment_id'] ?? '');
        $tenantId = (int) ($event->data['tenant_id'] ?? 0);
        $amount = (int) ($event->data['charged_amount'] ?? $event->data['amount'] ?? 0);
        if ($paymentId === '' || $tenantId < 1 || $amount < 1) {
            return; // nothing was paid (or the payment id is not known yet): no invoice
        }

        try {
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                return;
            }
            if (! InvoiceService::gatewayAllowed((string) ($event->data['gateway'] ?? ''))) {
                return;
            }

            $this->invoices->issueForPayment(
                $tenant,
                $paymentId,
                $amount,
                InvoiceService::describeSubscription((string) $event->data['plan'], (string) ($event->data['interval'] ?? 'month')),
                isset($event->data['subscription_id']) ? (int) $event->data['subscription_id'] : null,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
