<?php

namespace App\Modules\Invoicing\Support;

/** Rupee amounts in words with Indian grouping (thousand, lakh, crore). */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** 12345678 paise => "Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only". */
    public static function fromPaise(int $paise): string
    {
        if ($paise < 0) {
            throw new \InvalidArgumentException('Amounts in words are for non-negative paise.');
        }
        $rupees = intdiv($paise, 100);
        $rest = $paise % 100;

        $text = 'Rupees '.($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($rest > 0) {
            $text .= ' and '.self::number($rest).' Paise';
        }

        return $text.' Only';
    }

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $unit => $name) {
            if ($n >= $unit) {
                $parts[] = self::number(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n >= 100) {
            $parts[] = self::ONES[intdiv($n, 100)].' Hundred';
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
