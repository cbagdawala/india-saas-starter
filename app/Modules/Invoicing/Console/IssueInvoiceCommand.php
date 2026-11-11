<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/** Operator tool: issue a manual (not gateway-paid) invoice, e.g. an annual or offline sale paid by UPI / bank transfer. */
class IssueInvoiceCommand extends Command
{
    protected $signature = 'invoicing:issue {tenant : Workspace id} {description : Line description} {amount : Amount in paise} {--quantity=1} {--sac=} {--discount=0}';

    protected $description = 'Issue a manual GST tax invoice (unpaid) for a workspace. Amount is per unit, in paise; see INVOICING_PRICES_INCLUDE_TAX.';

    public function handle(InvoiceService $invoices): int
    {
        $tenant = Tenant::query()->find((int) $this->argument('tenant'));
        if (! $tenant) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }

        try {
            $invoice = $invoices->issue($tenant, [[
                'description' => (string) $this->argument('description'),
                'quantity' => (int) $this->option('quantity'),
                'unit_price' => (int) $this->argument('amount'),
                'discount' => (int) $this->option('discount'),
            ] + ($this->option('sac') ? ['sac' => (string) $this->option('sac')] : [])], ['payment_method' => 'manual']);
        } catch (InvoicingException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($invoice->number);

        return self::SUCCESS;
    }
}
