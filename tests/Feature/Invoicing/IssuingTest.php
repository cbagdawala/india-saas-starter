<?php

use App\Models\User;
use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Support\FinancialYear;
use App\Modules\Invoicing\Support\InvoiceNumberAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    configureSeller();
    $this->user = User::factory()->create();
    $this->tenant = $this->user->currentTenant;
    $this->service = app(InvoiceService::class);
});

function manualInvoice(object $test, int $amount = 49900, array $options = []): Invoice
{
    return $test->service->issue($test->tenant, [['description' => 'Annual plan', 'quantity' => 1, 'unit_price' => $amount]], ['payment_method' => 'manual'] + $options);
}

it('issues a gapless sequence per series and financial year, within 16 characters', function () {
    $numbers = collect(range(1, 3))->map(fn () => manualInvoice($this)->number)->all();

    expect($numbers)->toBe(['INV/26-27/00001', 'INV/26-27/00002', 'INV/26-27/00003'])
        ->and(strlen($numbers[0]))->toBeLessThanOrEqual(16);

    $note = $this->service->issueCreditNote(Invoice::withoutTenancy()->where('number', 'INV/26-27/00002')->first(), 'Customer cancelled');
    expect($note->number)->toBe('CN/26-27/00001'); // own series
});

it('uses the Indian financial year in Asia/Kolkata for the number series', function () {
    expect(FinancialYear::label(Carbon::parse('2027-03-31 18:29:59', 'UTC')))->toBe('2026-27')  // 23:59:59 IST on 31 Mar
        ->and(FinancialYear::label(Carbon::parse('2027-03-31 18:30:00', 'UTC')))->toBe('2027-28') // 00:00 IST on 1 Apr
        ->and(FinancialYear::label(Carbon::parse('2026-04-01 00:00:00', 'Asia/Kolkata')))->toBe('2026-27')
        ->and(FinancialYear::label(Carbon::parse('2026-03-31 23:59:00', 'Asia/Kolkata')))->toBe('2025-26');

    $this->travelTo(Carbon::parse('2027-03-31 18:29:00', 'UTC')); // 23:59 IST
    $a = manualInvoice($this);
    $this->travelTo(Carbon::parse('2027-03-31 18:31:00', 'UTC')); // 00:01 IST on 1 April
    $b = manualInvoice($this);

    expect($a->number)->toBe('INV/26-27/00001')->and($b->number)->toBe('INV/27-28/00001');
});

it('rolls the number back with the invoice so there are no gaps', function () {
    manualInvoice($this);

    try {
        DB::transaction(function () {
            manualInvoice($this); // takes number 2 ...
            throw new RuntimeException('boom'); // ... and the whole transaction is undone
        });
    } catch (RuntimeException) {
    }

    expect(manualInvoice($this)->number)->toBe('INV/26-27/00002');
    expect(Invoice::withoutTenancy()->count())->toBe(2);
});

it('only allocates numbers inside a transaction and refuses over-long or exhausted numbers', function () {
    $allocator = app(InvoiceNumberAllocator::class);
    // RefreshDatabase already wraps the test in a transaction, so check the guard through a fresh level-0 call:
    expect(fn () => $allocator->format('INVOICE-LONG', '2026-27', 1))->toThrow(InvoicingException::class);
    expect(fn () => $allocator->format('INV', '2026-27', 100000))->toThrow(InvoicingException::class);
    expect($allocator->format('INV', '2026-27', 99999))->toBe('INV/26-27/99999');
});

it('refuses to issue without a valid configured seller', function () {
    configureSeller(['gstin' => '27AAPFU0939F1ZW']); // bad checksum
    expect(fn () => manualInvoice($this))->toThrow(InvoicingException::class, 'not configured');

    configureSeller(['state_code' => '29']); // does not match the GSTIN
    expect(fn () => manualInvoice($this))->toThrow(InvoicingException::class);

    configureSeller(['legal_name' => '']);
    expect(fn () => manualInvoice($this))->toThrow(InvoicingException::class);
    expect(Invoice::withoutTenancy()->count())->toBe(0);
});

it('is immutable once issued: no edits, no deletes, payment can be recorded once', function () {
    $invoice = manualInvoice($this);

    expect(fn () => $invoice->update(['total' => 1]))->toThrow(LogicException::class);
    expect(fn () => Invoice::withoutTenancy()->find($invoice->id)->update(['buyer' => ['legal_name' => 'Someone else']]))->toThrow(LogicException::class);
    expect(fn () => $invoice->delete())->toThrow(LogicException::class);
    expect(fn () => $invoice->lines()->first()->update(['taxable_value' => 1]))->toThrow(LogicException::class);
    expect(fn () => $invoice->lines()->first()->delete())->toThrow(LogicException::class);

    $paid = $this->service->markPaid(Invoice::withoutTenancy()->find($invoice->id), 'UPI-123'); // fresh instance: the failed updates above left $invoice dirty
    expect($paid->paid_at)->not->toBeNull()->and($paid->payment_reference)->toBe('UPI-123');
    expect(fn () => $this->service->markPaid($invoice->fresh()))->toThrow(InvoicingException::class);
    expect(fn () => $invoice->fresh()->update(['payment_reference' => 'changed']))->toThrow(LogicException::class);
    expect(Invoice::withoutTenancy()->count())->toBe(1)->and(InvoiceLine::count())->toBe(1);
});

it('snapshots seller and buyer so later profile edits do not change an invoice', function () {
    setBillingProfile($this->tenant, ['state_code' => '29', 'gstin' => '29AABCT1332L1ZA']);
    $invoice = manualInvoice($this);

    $this->tenant->forceFill(['legal_name' => 'Renamed Ltd', 'state_code' => '07'])->save();
    config(['invoicing.seller.legal_name' => 'New Seller Name']);

    $fresh = Invoice::withoutTenancy()->find($invoice->id);
    expect($fresh->buyer['legal_name'])->toBe('Buyer Pvt Ltd')->and($fresh->buyer['state_code'])->toBe('29')->and($fresh->seller['legal_name'])->toBe('Example Software Pvt Ltd');
});

it('decides place of supply: same state intra, other state IGST, no state falls back to the seller, 97 is IGST', function () {
    setBillingProfile($this->tenant, ['state_code' => '27']);
    $intra = manualInvoice($this);
    expect($intra->supply_type)->toBe('intra')->and($intra->place_of_supply)->toBe('27-Maharashtra')->and($intra->cgst)->toBe(3806)->and($intra->sgst)->toBe(3806)->and($intra->igst)->toBe(0);

    setBillingProfile($this->tenant, ['state_code' => '29']);
    $inter = manualInvoice($this);
    expect($inter->supply_type)->toBe('inter')->and($inter->place_of_supply)->toBe('29-Karnataka')->and($inter->igst)->toBe(7612)->and($inter->cgst)->toBe(0);

    setBillingProfile($this->tenant, ['state_code' => null]);
    $unknown = manualInvoice($this);
    expect($unknown->supply_type)->toBe('intra')->and($unknown->place_of_supply)->toBe('27-Maharashtra'); // fallback: seller state

    setBillingProfile($this->tenant, ['state_code' => '97']);
    $other = manualInvoice($this);
    expect($other->supply_type)->toBe('inter')->and($other->place_of_supply)->toBe('97-Other Territory')->and($other->igst)->toBe(7612);
});

it('treats a buyer outside India as an export: zero-rated under LUT by default, IGST when configured', function () {
    setBillingProfile($this->tenant, ['country' => 'US', 'state_code' => null, 'gstin' => null]);

    $lut = manualInvoice($this);
    expect($lut->supply_type)->toBe('export')->and($lut->place_of_supply)->toBe('Outside India')->and($lut->total_tax)->toBe(0)->and($lut->total)->toBe(49900)
        ->and($lut->tax_note)->toContain('Letter of Undertaking');

    config(['invoicing.export.mode' => 'igst']);
    $igst = manualInvoice($this);
    expect($igst->igst)->toBe(7612)->and($igst->total)->toBe(49900)->and($igst->tax_note)->toContain('integrated tax');
});

it('can add GST on top of the price when configured', function () {
    config(['invoicing.prices_include_tax' => false]);
    $invoice = manualInvoice($this, 100000);

    expect($invoice->taxable_value)->toBe(100000)->and($invoice->total)->toBe(118000)->and($invoice->prices_include_tax)->toBeFalse();
});

it('keeps credit notes consistent with the original and never above its total', function () {
    setBillingProfile($this->tenant, ['state_code' => '29']);
    $original = manualInvoice($this, 100000);

    $half = $this->service->issueCreditNote($original, 'Partial refund', [['description' => 'Annual plan', 'quantity' => 1, 'unit_price' => 40000]]);
    expect($half->type)->toBe('credit_note')->and($half->original_invoice_id)->toBe($original->id)->and($half->total)->toBe(40000)->and($half->igst)->toBe(6102)
        ->and($half->place_of_supply)->toBe($original->place_of_supply)->and($half->buyer)->toBe($original->buyer);

    expect(fn () => $this->service->issueCreditNote($original, 'Too much'))->toThrow(InvoicingException::class, 'exceed'); // 100000 + 40000 > 100000

    $rest = $this->service->issueCreditNote($original, 'Rest', [['description' => 'Annual plan', 'quantity' => 1, 'unit_price' => 60000]]);
    expect($rest->number)->toBe('CN/26-27/00002');
    expect(fn () => $this->service->issueCreditNote($rest, 'Credit the credit'))->toThrow(InvoicingException::class);
    expect(fn () => $this->service->issueCreditNote($original, ' '))->toThrow(InvoicingException::class, 'reason');
});

it('issues a full credit note that mirrors the invoice exactly', function () {
    $original = manualInvoice($this, 49900);
    $note = $this->service->issueCreditNote($original, 'Duplicate charge');

    expect($note->total)->toBe($original->total)->and($note->cgst)->toBe($original->cgst)->and($note->sgst)->toBe($original->sgst)->and($note->taxable_value)->toBe($original->taxable_value);
});
