<?php

namespace App\Modules\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Lines belong to an invoice and are as immutable as it is. Access them through Invoice::lines() (tenant-scoped). */
class InvoiceLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice lines are immutable.'));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
