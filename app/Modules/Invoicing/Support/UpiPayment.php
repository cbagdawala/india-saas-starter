<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Invoicing\Models\Invoice;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * UPI deep link (upi://pay?pa=&pn=&am=&cu=INR&tn=&tr=) and QR for an unpaid manual invoice.
 * A link or QR only starts a payment in the payer's UPI app. Nothing here (or anywhere in this kit) learns whether the
 * payer actually paid: someone has to reconcile the bank / UPI statement and mark the invoice paid.
 */
class UpiPayment
{
    /** NPCI-style virtual payment address: handle@psp. */
    public static function isValidVpa(string $vpa): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,255}@[a-zA-Z][a-zA-Z0-9]{2,63}$/D', $vpa);
    }

    /** Only unpaid, manually-collected tax invoices (not credit notes, not gateway payments). */
    public function applies(Invoice $invoice): bool
    {
        return $invoice->type === 'invoice' && $invoice->payment_method === 'manual' && $invoice->paid_at === null && $this->vpa() !== null;
    }

    public function link(Invoice $invoice): ?string
    {
        $vpa = $this->vpa();
        if ($vpa === null || ! $this->applies($invoice)) {
            return null;
        }

        $name = (string) (config('invoicing.upi.payee_name') ?: config('invoicing.seller.legal_name') ?: 'Merchant');
        $query = [
            'pa' => $vpa,
            'pn' => $name,
            'am' => number_format($invoice->total / 100, 2, '.', ''),
            'cu' => 'INR',
            'tn' => 'Invoice '.$invoice->number,
            'tr' => preg_replace('/[^A-Za-z0-9]/', '', $invoice->number),
        ];

        return 'upi://pay?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{link: string, qr: string, note: string}|null qr is an SVG data URI */
    public function forInvoice(Invoice $invoice): ?array
    {
        $link = $this->link($invoice);

        return $link === null ? null : [
            'link' => $link,
            'qr' => $this->qrDataUri($link),
            'note' => 'Paying through this link does not update the invoice automatically. It is marked paid after the payment is reconciled.',
        ];
    }

    public function qrDataUri(string $content): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($content);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function vpa(): ?string
    {
        $vpa = trim((string) config('invoicing.upi.vpa'));

        return $vpa !== '' && self::isValidVpa($vpa) ? $vpa : null;
    }
}
