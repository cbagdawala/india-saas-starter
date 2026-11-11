<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Invoicing\Exceptions\InvoicingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Gapless numbering per seller, series and financial year.
 *
 * `next()` must run inside the transaction that inserts the invoice. It locks the counter row (SELECT ... FOR UPDATE),
 * so concurrent issuers queue up on it; the increment and the invoice row commit together, and a rollback releases
 * the number (no gaps). The first number of a series is created on demand; two issuers racing to create it can
 * deadlock on MySQL (one is aborted and retried by the transaction attempts in InvoiceService).
 */
class InvoiceNumberAllocator
{
    public const WARN_AT = 0.9; // log (and show in /health) once a series is 90% used

    public const MAX_LENGTH = 16; // CGST Rule 46: serial number of at most 16 characters

    /** @return array{sequence: int, number: string} */
    public function next(string $sellerKey, string $series, string $financialYear): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Invoice numbers must be allocated inside the invoice insert transaction.');
        }

        $where = ['seller_key' => $sellerKey, 'series' => $series, 'financial_year' => $financialYear];
        $lock = fn () => DB::table('invoice_sequences')->where($where)->lockForUpdate()->first();

        $row = $lock();
        if (! $row) {
            DB::table('invoice_sequences')->insertOrIgnore($where + ['last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = $lock();
        }

        $sequence = $row->last_number + 1;
        $number = $this->format($series, $financialYear, $sequence);

        DB::table('invoice_sequences')->where('id', $row->id)->update(['last_number' => $sequence, 'updated_at' => now()]);

        if ($sequence >= self::capacity() * self::WARN_AT) {
            Log::warning('Invoice series is nearly full: raise invoicing.number_width (max total length is 16).', ['series' => $series, 'financial_year' => $financialYear, 'used' => $sequence, 'capacity' => self::capacity()]);
        }

        return ['sequence' => $sequence, 'number' => $number];
    }

    /** Highest sequence a series can reach with the configured width (99999 for width 5). */
    public static function capacity(): int
    {
        return 10 ** (int) config('invoicing.number_width', 5) - 1;
    }

    /**
     * Fail at boot, not at 2 a.m. on invoice number 100000: every series must fit in 16 characters at full width.
     *
     * @throws InvoicingException
     */
    public function assertConfigured(): void
    {
        foreach (['invoice', 'credit_note'] as $key) {
            $this->format((string) config("invoicing.series.{$key}"), '2026-27', self::capacity());
        }
    }

    public function format(string $series, string $financialYear, int $sequence): string
    {
        $width = (int) config('invoicing.number_width', 5);
        if ($sequence > self::capacity()) {
            throw new InvoicingException("Invoice series {$series} is exhausted for {$financialYear}.", 500);
        }

        $number = $series.'/'.FinancialYear::short($financialYear).'/'.str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
        if (strlen($number) > self::MAX_LENGTH || ! preg_match('#^[A-Za-z0-9/-]+$#D', $number)) {
            throw new InvoicingException("Invoice number {$number} is longer than 16 characters or has invalid characters. Shorten INVOICING_PREFIX.", 500);
        }

        return $number;
    }
}
