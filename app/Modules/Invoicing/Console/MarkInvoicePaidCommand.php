<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Illuminate\Console\Command;

/** Operator tool: after reconciling the bank / UPI statement, mark a manual invoice as paid. UPI links never do this by themselves. */
class MarkInvoicePaidCommand extends Command
{
    protected $signature = 'invoicing:mark-paid {number : Invoice number, e.g. INV/26-27/00001} {--reference= : UPI / bank reference}';

    protected $description = 'Mark a manual invoice as paid after the payment is reconciled.';

    public function handle(InvoiceService $invoices): int
    {
        $invoice = Invoice::withoutTenancy()->where('number', $this->argument('number'))->first();
        if (! $invoice) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        try {
            $invoices->markPaid($invoice, $this->option('reference'));
        } catch (InvoicingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$invoice->number} marked paid.");

        return self::SUCCESS;
    }
}
