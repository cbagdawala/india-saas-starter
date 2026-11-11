<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Operator tool: full credit note against an issued invoice (the only way to correct one). */
class IssueCreditNoteCommand extends Command
{
    protected $signature = 'invoicing:credit-note {number : Invoice number} {reason : Why the invoice is being credited} {--amount= : Credit only this amount in paise (same pricing basis as the invoice: GST-inclusive when the invoice was) instead of the whole invoice} {--description= : Line description for a partial credit note}';

    protected $description = 'Issue a credit note reversing a whole invoice, or part of it with --amount. Refunds do not raise credit notes automatically.';

    public function handle(InvoiceService $invoices): int
    {
        $invoice = Invoice::withoutTenancy()->where('number', $this->argument('number'))->first();
        if (! $invoice) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        try {
            $lines = null;
            if ($this->option('amount') !== null) {
                $first = $invoice->lines()->firstOrFail();
                $lines = [['description' => (string) ($this->option('description') ?: 'Credit: '.$first->description), 'sac' => $first->sac, 'quantity' => 1, 'unit_price' => (int) $this->option('amount'), 'rate_bp' => $first->rate_bp]];
            }
            $note = $invoices->issueCreditNote($invoice, (string) $this->argument('reason'), $lines);
        } catch (InvoicingException|\InvalidArgumentException|ModelNotFoundException $e) {
            $this->error($e instanceof ModelNotFoundException ? 'The invoice has no workspace or lines to credit.' : $e->getMessage());

            return self::FAILURE;
        }

        $this->info($note->number);

        return self::SUCCESS;
    }
}
