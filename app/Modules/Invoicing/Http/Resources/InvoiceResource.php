<?php

namespace App\Modules\Invoicing\Http\Resources;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Support\AmountInWords;
use App\Modules\Invoicing\Support\UpiPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'number' => $this->number,
            'issued_at' => $this->issued_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->isCreditNote() ? 'credited' : ($this->paid_at ? 'paid' : 'unpaid'),
            'payment_method' => $this->payment_method,
            'supply_type' => $this->supply_type,
            'place_of_supply' => $this->place_of_supply,
            'currency' => $this->currency,
            'taxable_value' => $this->taxable_value,
            'cgst' => $this->cgst,
            'sgst' => $this->sgst,
            'igst' => $this->igst,
            'total_tax' => $this->total_tax,
            'total' => $this->total,
            'original_invoice_id' => $this->original_invoice_id,
            // Detail-only fields
            'total_in_words' => $this->when($this->relationLoaded('lines'), fn () => AmountInWords::fromPaise($this->total)),
            'tax_note' => $this->when($this->relationLoaded('lines'), $this->tax_note),
            'credit_reason' => $this->when($this->relationLoaded('lines'), $this->credit_reason),
            'reverse_charge' => $this->when($this->relationLoaded('lines'), false),
            'seller' => $this->when($this->relationLoaded('lines'), $this->seller),
            'buyer' => $this->when($this->relationLoaded('lines'), $this->buyer),
            'lines' => $this->when($this->relationLoaded('lines'), fn () => $this->lines->map(fn ($l) => $l->only(['position', 'description', 'sac', 'quantity', 'unit_price', 'discount', 'taxable_value', 'rate_bp', 'cgst', 'sgst', 'igst', 'total']))->values()),
            'upi' => $this->when($this->relationLoaded('lines'), fn () => app(UpiPayment::class)->forInvoice($this->resource)),
        ];
    }
}
