<?php

namespace App\Modules\Invoicing\Support;

final class Inr
{
    /** 123456789 paise => "12,34,567.89" (Indian digit grouping). */
    public static function format(int $paise): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = (string) intdiv($paise, 100);
        $last3 = substr($rupees, -3);
        $rest = substr($rupees, 0, -3);
        if ($rest !== '') {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).',';
        }

        return ($negative ? '-' : '').$rest.$last3.'.'.str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function rate(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }
}
