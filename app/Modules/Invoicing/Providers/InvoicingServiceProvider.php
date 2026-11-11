<?php

namespace App\Modules\Invoicing\Providers;

use App\Modules\Billing\Events\BillingEventOccurred;
use App\Modules\Invoicing\Listeners\IssueInvoiceForPayment;
use App\Modules\Invoicing\Support\InvoiceNumberAllocator;
use App\Modules\Invoicing\Support\SellerConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;

class InvoicingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Misconfigured numbering would only fail at invoice number 100000: refuse to start instead (every environment).
        app(InvoiceNumberAllocator::class)->assertConfigured();

        // Real payments without a valid seller would silently produce no invoices: refuse to start outside local/testing.
        if (SellerConfig::required() && ! $this->app->environment('local', 'testing') && ($problems = SellerConfig::problems()) !== []) {
            throw new LogicException('Invoicing is enabled with a real billing driver but the seller is not configured: '.implode('; ', $problems).'. Set INVOICING_SELLER_*, or INVOICING_ENABLED=false if you do not issue GST invoices.');
        }

        Event::listen(BillingEventOccurred::class, IssueInvoiceForPayment::class);
    }
}
