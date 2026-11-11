<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Support\UpiPayment;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders an invoice / credit note as an A4 PDF. Remote resources are disabled: the document is self-contained. */
class InvoicePdf
{
    public function __construct(private readonly UpiPayment $upi) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('lines', 'original');

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [resource_path('views')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('invoicing.pdf', [
            'invoice' => $invoice,
            'upi' => $this->upi->forInvoice($invoice),
            'summary' => $this->sacSummary($invoice),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @return list<array<string, int|string>> totals grouped by SAC/HSN and rate */
    private function sacSummary(Invoice $invoice): array
    {
        $groups = [];
        foreach ($invoice->lines as $line) {
            $key = $line->sac.'|'.$line->rate_bp;
            $groups[$key] ??= ['sac' => $line->sac, 'rate_bp' => $line->rate_bp, 'taxable_value' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            foreach (['taxable_value', 'cgst', 'sgst', 'igst'] as $k) {
                $groups[$key][$k] += $line->{$k};
            }
        }

        return array_values($groups);
    }
}
