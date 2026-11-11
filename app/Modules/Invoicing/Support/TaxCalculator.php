<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Invoicing\Enums\SupplyType;
use InvalidArgumentException;

/**
 * GST arithmetic in integer paise (no floats anywhere).
 *
 * Rounding rule (needs CA confirmation, see README): tax is computed PER LINE and rounded half up to the paisa; the
 * invoice totals are the plain sums of the line values (never recomputed from the invoice total). For an intra-state
 * line the line tax T is split CGST = floor(T / 2) and SGST = T - CGST, so CGST + SGST equals the tax exactly and
 * SGST carries the odd paisa. Invoice totals are not rounded to whole rupees.
 *
 * Line input: description, sac, quantity (>= 1), unit_price (paise), discount (paise, default 0), rate_bp (1800 = 18%).
 *  - prices include tax: gross = qty * unit_price - discount; taxable = round(gross * 10000 / (10000 + rate)); tax = gross - taxable.
 *  - prices exclude tax: taxable = qty * unit_price - discount; tax = round(taxable * rate / 10000).
 *  - export under LUT: no tax; taxable = the whole amount.
 */
final class TaxCalculator
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  bool  $exportZeroRated  true for an export under LUT (only relevant with SupplyType::Export)
     * @return array{lines: list<array<string, mixed>>, totals: array<string, int>}
     */
    public static function calculate(array $lines, SupplyType $supply, bool $pricesIncludeTax, bool $exportZeroRated = false): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line.');
        }

        $out = [];
        $totals = ['taxable_value' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total_tax' => 0, 'total' => 0];

        foreach (array_values($lines) as $i => $line) {
            $qty = (int) $line['quantity'];
            $unit = (int) $line['unit_price'];
            $discount = (int) ($line['discount'] ?? 0);
            $rate = (int) $line['rate_bp'];

            if ($qty < 1 || $unit < 0 || $discount < 0 || $rate < 0 || $rate > 10000) {
                throw new InvalidArgumentException('Invalid line values.');
            }
            $gross = $qty * $unit - $discount;
            if ($gross < 0) {
                throw new InvalidArgumentException('A discount cannot exceed the line amount.');
            }

            if ($supply === SupplyType::Export && $exportZeroRated) {
                $taxable = $gross;
                $tax = 0;
            } elseif ($pricesIncludeTax) {
                $taxable = self::divideRound($gross * 10000, 10000 + $rate);
                $tax = $gross - $taxable;
            } else {
                $taxable = $gross;
                $tax = self::divideRound($taxable * $rate, 10000);
            }

            $cgst = $sgst = $igst = 0;
            if ($supply === SupplyType::Intra) {
                $cgst = intdiv($tax, 2);
                $sgst = $tax - $cgst;
            } else {
                $igst = $tax;
            }

            $out[] = [
                'position' => $i + 1,
                'description' => (string) $line['description'],
                'sac' => (string) $line['sac'],
                'quantity' => $qty,
                'unit_price' => $unit,
                'discount' => $discount,
                'taxable_value' => $taxable,
                'rate_bp' => $rate,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total' => $taxable + $tax,
            ];
            $totals['taxable_value'] += $taxable;
            $totals['cgst'] += $cgst;
            $totals['sgst'] += $sgst;
            $totals['igst'] += $igst;
            $totals['total_tax'] += $tax;
            $totals['total'] += $taxable + $tax;
        }

        return ['lines' => $out, 'totals' => $totals];
    }

    /** Integer division rounding half up (non-negative operands). */
    public static function divideRound(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
