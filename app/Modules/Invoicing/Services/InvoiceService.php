<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Invoicing\Enums\SupplyType;
use App\Modules\Invoicing\Exceptions\InvoicingException;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Support\BuyerProfile;
use App\Modules\Invoicing\Support\FinancialYear;
use App\Modules\Invoicing\Support\Gstin;
use App\Modules\Invoicing\Support\GstStateCodes;
use App\Modules\Invoicing\Support\InvoiceNumberAllocator;
use App\Modules\Invoicing\Support\SellerConfig;
use App\Modules\Invoicing\Support\TaxCalculator;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(private readonly InvoiceNumberAllocator $numbers) {}

    /** The fake gateway moves no money, so it never produces a legal document outside local/testing. */
    public static function gatewayAllowed(string $gateway): bool
    {
        return $gateway !== 'fake' || app()->environment('local', 'testing');
    }

    public static function describeSubscription(string $plan, string $interval): string
    {
        $name = (string) config("billing.plans.{$plan}.name", $plan);

        return "{$name} plan subscription (per {$interval})";
    }

    /**
     * Issue the tax invoice for a gateway payment. Idempotent per payment id: a repeated confirm / webhook returns the
     * invoice that already exists. $grossPaise is what the customer was charged. With prices_include_tax (default) it
     * is the invoice total; otherwise it is the taxable value and GST is added on top.
     */
    public function issueForPayment(Tenant $tenant, string $paymentId, int $grossPaise, string $description, ?int $subscriptionId = null): Invoice
    {
        $existing = Invoice::withoutTenancy()->where('type', Invoice::TYPE_INVOICE)->where('payment_id', $paymentId)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return $this->issue($tenant, [$this->singleLine($description, $grossPaise)], [
                'payment_id' => $paymentId,
                'payment_method' => 'gateway',
                'paid' => true,
                'subscription_id' => $subscriptionId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two workers raced on the same payment; the unique (type, payment_id) index let exactly one win.
            return Invoice::withoutTenancy()->where('type', Invoice::TYPE_INVOICE)->where('payment_id', $paymentId)->firstOrFail();
        }
    }

    /**
     * Issue a tax invoice. $lines: description, quantity, unit_price (paise), discount?, sac?, rate_bp?.
     * $options: payment_method (gateway|manual), paid (bool), payment_id, subscription_id, issued_at.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $options
     */
    public function issue(Tenant $tenant, array $lines, array $options = []): Invoice
    {
        $seller = $this->seller();
        $buyer = $this->buyer($tenant);
        $requestedAt = $options['issued_at'] ?? null; // null = "now", taken under the series lock
        $paid = (bool) ($options['paid'] ?? false);
        $lines = array_map(fn (array $l) => $l + ['sac' => config('invoicing.default_sac'), 'rate_bp' => config('invoicing.default_rate_bp'), 'discount' => 0], $lines);

        [$supply, $placeOfSupply, $note, $zeroRated] = $this->placeOfSupply($seller, $buyer);
        $includes = (bool) config('invoicing.prices_include_tax');
        $calc = TaxCalculator::calculate($lines, $supply, $includes, $zeroRated);

        return $this->persist(Invoice::TYPE_INVOICE, $tenant, $seller, $buyer, $requestedAt, $calc, [
            'supply_type' => $supply->value,
            'place_of_supply' => $placeOfSupply,
            'tax_note' => $note,
            'prices_include_tax' => $includes,
            'payment_method' => (string) ($options['payment_method'] ?? 'manual'),
            'payment_id' => $options['payment_id'] ?? null,
            'subscription_id' => $options['subscription_id'] ?? null,
        ], $paid);
    }

    /**
     * Credit note against an invoice: same seller/buyer/supply as the original, its own number series. Without $lines it
     * reverses the whole invoice. With $lines (description, quantity, unit_price, discount?, sac?, rate_bp? in the
     * original's pricing mode) it credits part of it. Total credited can never exceed the original total.
     *
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function issueCreditNote(Invoice $original, string $reason, ?array $lines = null, ?CarbonInterface $issuedAt = null): Invoice
    {
        if ($original->isCreditNote()) {
            throw new InvoicingException('A credit note cannot be credited.', 422);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvoicingException('A credit note needs a reason.', 422);
        }

        // withTrashed: an erased (soft-deleted) workspace still has invoices that may need a credit note.
        $tenant = Tenant::withTrashed()->findOrFail($original->tenant_id);
        $supply = SupplyType::from($original->supply_type);
        $zeroRated = $supply === SupplyType::Export && $original->total_tax === 0;

        $lines ??= $original->lines->map(fn (InvoiceLine $l) => [
            'description' => $l->description, 'sac' => $l->sac, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price,
            'discount' => $l->discount, 'rate_bp' => $l->rate_bp,
        ])->all();
        $lines = array_map(fn (array $l) => $l + ['sac' => config('invoicing.default_sac'), 'rate_bp' => config('invoicing.default_rate_bp'), 'discount' => 0], $lines);
        $calc = TaxCalculator::calculate($lines, $supply, $original->prices_include_tax, $zeroRated);

        return $this->atomically(function () use ($original, $reason, $tenant, $calc, $supply, $issuedAt) {
            // Serialise credit notes of one invoice so two cannot both pass the "not more than the original" check.
            Invoice::withoutTenancy()->whereKey($original->id)->lockForUpdate()->first();
            $already = (int) Invoice::withoutTenancy()->where('original_invoice_id', $original->id)->sum('total');
            if ($already + $calc['totals']['total'] > $original->total) {
                throw new InvoicingException('Credit notes cannot exceed the original invoice total.', 422);
            }

            return $this->persist(Invoice::TYPE_CREDIT_NOTE, $tenant, $original->seller, $original->buyer, $issuedAt, $calc, [
                'supply_type' => $supply->value,
                'place_of_supply' => $original->place_of_supply,
                'tax_note' => $original->tax_note,
                'prices_include_tax' => $original->prices_include_tax,
                'payment_method' => $original->payment_method,
                'original_invoice_id' => $original->id,
                'credit_reason' => Str::limit($reason, 255, ''),
            ], true); // a credit note is not collectable: it is settled when issued
        });
    }

    /** Record that a manual invoice was paid (once). The only change allowed after issue. */
    public function markPaid(Invoice $invoice, ?string $reference = null): Invoice
    {
        return $this->atomically(function () use ($invoice, $reference) {
            // Lock and re-read: two operators marking the same invoice paid at once must not both succeed.
            $locked = Invoice::withoutTenancy()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->isCreditNote() || $locked->paid_at !== null) {
                throw new InvoicingException('This document is already settled.', 409);
            }

            $locked->paid_at = now();
            $locked->payment_reference = $reference !== null ? Str::limit(trim($reference), 255, '') : null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Run in one transaction. At the outermost level MySQL deadlocks (two issuers creating a series' first counter row)
     * are retried; inside a caller's transaction the retry belongs to that caller, so the nested call just runs.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function atomically(callable $callback): mixed
    {
        return DB::transactionLevel() === 0 ? DB::transaction($callback, 3) : DB::transaction($callback);
    }

    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @param  array{lines: list<array<string, mixed>>, totals: array<string, int>}  $calc
     * @param  array<string, mixed>  $attributes
     */
    private function persist(string $type, Tenant $tenant, array $seller, array $buyer, ?CarbonInterface $requestedAt, array $calc, array $attributes, bool $settled = false): Invoice
    {
        $series = (string) config($type === Invoice::TYPE_INVOICE ? 'invoicing.series.invoice' : 'invoicing.series.credit_note');
        $fy = FinancialYear::label($requestedAt ?? now());

        // An explicit date: no back-dating into a closed financial year, no future dates (a number's date must not run ahead of the clock).
        if ($requestedAt !== null && ($fy !== FinancialYear::label(now()) || $requestedAt->isAfter(now()->addMinute()))) {
            throw new InvoicingException('The invoice date must be today (in the current financial year). Back-dated and future-dated invoices are refused.', 422);
        }

        // Allocate the number and insert the invoice in ONE transaction: a failure rolls the number back (gapless).
        $invoice = $this->atomically(function () use ($type, $tenant, $seller, $buyer, $requestedAt, $settled, $calc, $attributes, $series, $fy) {
            ['sequence' => $sequence, 'number' => $number] = $this->numbers->next($seller['gstin'], $series, $fy);

            // The counter row is locked now, so this read is consistent: numbers must not run backwards in time.
            $latest = Invoice::withoutTenancy()->where(['seller_gstin' => $seller['gstin'], 'series' => $series, 'financial_year' => $fy])->max('issued_at');
            $latestAt = $latest !== null ? Carbon::parse($latest, 'UTC') : null;

            if ($requestedAt !== null) {
                $issuedAt = $requestedAt;
                if ($latestAt !== null && $issuedAt->lt($latestAt)) {
                    throw new InvoicingException('The invoice date is earlier than the latest invoice in this series. Back-dating is refused.', 422);
                }
            } else {
                // "Now" is read AFTER the lock, so issuers queued on the counter get increasing timestamps; if another app server's
                // clock runs slightly ahead, clamp to the latest date instead of failing (no date ever runs backwards).
                $issuedAt = now();
                if ($latestAt !== null && $issuedAt->lt($latestAt)) {
                    $issuedAt = $latestAt;
                }
            }
            if (FinancialYear::label($issuedAt) !== $fy) {
                throw new InvoicingException('The financial year changed while the invoice was being issued. Try again.', 503);
            }

            $invoice = new Invoice($attributes + $calc['totals']);
            $invoice->tenant_id = $tenant->id;
            $invoice->type = $type;
            $invoice->series = $series;
            $invoice->financial_year = $fy;
            $invoice->sequence = $sequence;
            $invoice->number = $number;
            $invoice->seller_gstin = $seller['gstin'];
            $invoice->seller = $seller;
            $invoice->buyer = $buyer;
            $invoice->issued_at = $issuedAt;
            $invoice->paid_at = $settled ? $issuedAt : null;
            $invoice->currency = 'INR';
            $invoice->save();

            foreach ($calc['lines'] as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice;
        });

        return $invoice->load('lines');
    }

    /**
     * Supply type and place of supply (IGST Act s.12 for services, simplified):
     *  - buyer outside India: export. LUT mode = zero-rated; igst mode = IGST charged. Place: "Outside India".
     *  - buyer in India: the buyer's state (from the billing profile, whose GSTIN, when given, must agree);
     *    if the buyer has no state on file we fall back to the SELLER's state (supply treated as intra-state).
     *  - same state as the seller: CGST + SGST; any other code (including 97 Other Territory): IGST.
     *
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @return array{0: SupplyType, 1: string, 2: ?string, 3: bool}
     */
    private function placeOfSupply(array $seller, array $buyer): array
    {
        if (($buyer['country'] ?? 'IN') !== 'IN') {
            $lut = config('invoicing.export.mode') !== 'igst';

            return [SupplyType::Export, 'Outside India', $lut ? config('invoicing.export.lut_note') : config('invoicing.export.igst_note'), $lut];
        }

        $code = GstStateCodes::normalize($buyer['state_code'] ?? null) ?? $seller['state_code'];
        $place = $code.'-'.GstStateCodes::name((int) $code);

        return [$code === $seller['state_code'] ? SupplyType::Intra : SupplyType::Inter, $place, null, false];
    }

    /** @return array<string, mixed> validated seller snapshot; refuses to issue with an incomplete seller */
    private function seller(): array
    {
        if (($problems = SellerConfig::problems()) !== []) {
            throw new InvoicingException('Invoicing is not configured: '.implode('; ', $problems).'.', 503);
        }

        $s = (array) config('invoicing.seller');
        $gstin = strtoupper(trim((string) $s['gstin']));
        $state = (string) GstStateCodes::normalize($s['state_code']);

        return [
            'legal_name' => trim((string) $s['legal_name']),
            'address' => trim((string) $s['address']),
            'gstin' => $gstin,
            'state_code' => $state,
            'state_name' => GstStateCodes::name((int) $state),
            'pan' => $s['pan'] ?: substr($gstin, 2, 10),
            'email' => $s['email'] ?: null,
        ];
    }

    /**
     * Snapshot of the workspace billing profile. Gaps are filled so a payment never fails to get an invoice
     * (name falls back to the workspace name); the missing state falls back to the seller's (see placeOfSupply).
     *
     * @return array<string, mixed>
     */
    private function buyer(Tenant $tenant): array
    {
        $country = strtoupper((string) ($tenant->country ?: 'IN'));
        $state = BuyerProfile::stateCode($tenant);

        return [
            'legal_name' => $tenant->legal_name ?: $tenant->name,
            'address' => collect([$tenant->address_line1, $tenant->address_line2, $tenant->city, $tenant->pincode])->filter()->implode(', '),
            'country' => $country,
            'state_code' => $state,
            'state_name' => $state ? GstStateCodes::name((int) $state) : null,
            'gstin' => $country === 'IN' && $tenant->gstin ? $tenant->gstin : null,
        ];
    }

    /** @return array<string, mixed> */
    private function singleLine(string $description, int $grossPaise): array
    {
        return ['description' => $description, 'quantity' => 1, 'unit_price' => $grossPaise];
    }
}
