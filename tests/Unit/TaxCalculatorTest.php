<?php

use App\Modules\Invoicing\Enums\SupplyType;
use App\Modules\Invoicing\Support\AmountInWords;
use App\Modules\Invoicing\Support\Inr;
use App\Modules\Invoicing\Support\TaxCalculator;

function line(int $unit, int $qty = 1, int $discount = 0, int $rate = 1800): array
{
    return ['description' => 'Plan', 'sac' => '998314', 'quantity' => $qty, 'unit_price' => $unit, 'discount' => $discount, 'rate_bp' => $rate];
}

it('splits a GST-inclusive intra-state price into CGST and SGST', function () {
    $r = TaxCalculator::calculate([line(49900)], SupplyType::Intra, true);

    expect($r['totals'])->toBe(['taxable_value' => 42288, 'cgst' => 3806, 'sgst' => 3806, 'igst' => 0, 'total_tax' => 7612, 'total' => 49900]);
});

it('charges IGST for inter-state supply', function () {
    $r = TaxCalculator::calculate([line(49900)], SupplyType::Inter, true);

    expect($r['totals'])->toMatchArray(['taxable_value' => 42288, 'cgst' => 0, 'sgst' => 0, 'igst' => 7612, 'total' => 49900]);
});

it('adds GST on top when prices exclude tax', function () {
    $r = TaxCalculator::calculate([line(100000)], SupplyType::Intra, false);

    expect($r['totals'])->toBe(['taxable_value' => 100000, 'cgst' => 9000, 'sgst' => 9000, 'igst' => 0, 'total_tax' => 18000, 'total' => 118000]);
});

it('gives the odd paisa to SGST so CGST + SGST is always exactly the tax', function () {
    // 5 paise at 18% exclusive: tax rounds to 1 paisa -> CGST 0, SGST 1.
    $r = TaxCalculator::calculate([line(5)], SupplyType::Intra, false);
    expect($r['totals'])->toMatchArray(['cgst' => 0, 'sgst' => 1, 'total_tax' => 1, 'total' => 6]);

    foreach ([1800, 1200, 500, 2800, 0] as $rate) {
        foreach (range(0, 3000) as $gross) {
            foreach ([true, false] as $inclusive) {
                $t = TaxCalculator::calculate([line($gross, 1, 0, $rate)], SupplyType::Intra, $inclusive)['totals'];
                expect($t['cgst'] + $t['sgst'])->toBe($t['total_tax'])
                    ->and($t['sgst'] - $t['cgst'])->toBeIn([0, 1])
                    ->and($t['taxable_value'] + $t['total_tax'])->toBe($t['total']);
                if ($inclusive) {
                    expect($t['total'])->toBe($gross); // what the customer paid is the invoice total
                }
            }
        }
    }
});

it('rounds per line and sums the lines for the invoice totals', function () {
    // Two lines of 33 paise (exclusive, 18%): each tax = round(5.94) = 6; invoice tax = 12, not round(11.88) = 12 here,
    // so also check a case where per-invoice rounding would differ: 3 x 1 paise: per line round(0.18)=0, sum 0 (per invoice: round(0.54)=1).
    $r = TaxCalculator::calculate([line(1), line(1), line(1)], SupplyType::Inter, false);
    expect($r['totals']['igst'])->toBe(0)->and(array_sum(array_column($r['lines'], 'igst')))->toBe(0);

    $r = TaxCalculator::calculate([line(33), line(33)], SupplyType::Intra, false);
    expect($r['totals']['total_tax'])->toBe(12)->and($r['lines'][0]['cgst'] + $r['lines'][0]['sgst'])->toBe(6);
});

it('applies quantity and discount before tax', function () {
    $r = TaxCalculator::calculate([line(10000, 3, 5000)], SupplyType::Inter, false);

    expect($r['lines'][0])->toMatchArray(['taxable_value' => 25000, 'igst' => 4500, 'total' => 29500]);
});

it('handles exports: zero-rated under LUT, or IGST', function () {
    $lut = TaxCalculator::calculate([line(49900)], SupplyType::Export, true, true);
    expect($lut['totals'])->toBe(['taxable_value' => 49900, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total_tax' => 0, 'total' => 49900]);

    $igst = TaxCalculator::calculate([line(49900)], SupplyType::Export, true, false);
    expect($igst['totals'])->toMatchArray(['igst' => 7612, 'total' => 49900]);
});

it('rejects invalid lines', function () {
    expect(fn () => TaxCalculator::calculate([], SupplyType::Intra, true))->toThrow(InvalidArgumentException::class);
    expect(fn () => TaxCalculator::calculate([line(100, 0)], SupplyType::Intra, true))->toThrow(InvalidArgumentException::class);
    expect(fn () => TaxCalculator::calculate([line(100, 1, 200)], SupplyType::Intra, true))->toThrow(InvalidArgumentException::class);
});

it('writes amounts in words with Indian grouping', function () {
    expect(AmountInWords::fromPaise(0))->toBe('Rupees Zero Only')
        ->and(AmountInWords::fromPaise(49900))->toBe('Rupees Four Hundred Ninety Nine Only')
        ->and(AmountInWords::fromPaise(12345678))->toBe('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only')
        ->and(AmountInWords::fromPaise(100))->toBe('Rupees One Only')
        ->and(AmountInWords::fromPaise(5))->toBe('Rupees Zero and Five Paise Only')
        ->and(AmountInWords::fromPaise(1000000000))->toBe('Rupees One Crore Only')
        ->and(AmountInWords::fromPaise(123456789000))->toBe('Rupees One Hundred Twenty Three Crore Forty Five Lakh Sixty Seven Thousand Eight Hundred Ninety Only')
        ->and(AmountInWords::fromPaise(1100000))->toBe('Rupees Eleven Thousand Only')
        ->and(AmountInWords::fromPaise(10000000))->toBe('Rupees One Lakh Only');
});

it('formats rupees with Indian digit grouping', function () {
    expect(Inr::format(49900))->toBe('499.00')->and(Inr::format(123456789))->toBe('12,34,567.89')->and(Inr::format(100000))->toBe('1,000.00')
        ->and(Inr::format(5))->toBe('0.05')->and(Inr::rate(1800))->toBe('18%')->and(Inr::rate(900))->toBe('9%')->and(Inr::rate(250))->toBe('2.5%');
});
