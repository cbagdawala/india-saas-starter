<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Invoicing\Policies\InvoicePolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A tax invoice or credit note. Once inserted it is immutable: no update (except recording the payment once), no
 * delete. Corrections are credit notes. Built only by InvoiceService. The seller/buyer snapshots are stored on the row.
 */
#[UsePolicy(InvoicePolicy::class)]
class Invoice extends Model
{
    use BelongsToTenant;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $guarded = [];

    /** Columns that may change after issue: how the payment was received, set once. */
    private const MUTABLE = ['paid_at', 'payment_reference', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $changed = array_keys($invoice->getDirty());
            if (array_diff($changed, self::MUTABLE) !== [] || $invoice->getOriginal('paid_at') !== null) {
                throw new LogicException('An issued invoice is immutable. Issue a credit note instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('An issued invoice cannot be deleted. Issue a credit note instead.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'seller' => 'array',
            'buyer' => 'array',
            'prices_include_tax' => 'boolean',
            'sequence' => 'integer',
            'taxable_value' => 'integer',
            'cgst' => 'integer',
            'sgst' => 'integer',
            'igst' => 'integer',
            'total_tax' => 'integer',
            'total' => 'integer',
        ];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }
}
