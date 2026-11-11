<?php

namespace App\Modules\Invoicing\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Indian financial year: 1 April to 31 March, evaluated in the configured zone (Asia/Kolkata). */
final class FinancialYear
{
    /** "2026-27" for any instant from 2026-04-01 00:00 IST to 2027-03-31 23:59:59 IST. */
    public static function label(DateTimeInterface $at): string
    {
        $local = Carbon::instance($at)->setTimezone((string) config('invoicing.timezone', 'Asia/Kolkata'));
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /** "26-27", the compact form used in invoice numbers. */
    public static function short(string $label): string
    {
        return substr($label, 2);
    }
}
