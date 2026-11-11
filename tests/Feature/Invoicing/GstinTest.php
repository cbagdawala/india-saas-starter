<?php

use App\Modules\Invoicing\Rules\ValidGstin;
use App\Modules\Invoicing\Support\Gstin;
use App\Modules\Invoicing\Support\GstStateCodes;
use Illuminate\Support\Facades\Validator;

function vector(string $file): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/india-data/{$file}")), true, flags: JSON_THROW_ON_ERROR);
}

it('matches every GSTIN vector (validity, first error kind, state code)', function () {
    $cases = vector('gstin.json')['cases'];
    expect(count($cases))->toBeGreaterThan(30);

    foreach ($cases as $case) {
        $error = Gstin::error($case['input']);
        if ($case['valid']) {
            expect($error)->toBeNull("{$case['input']} ({$case['note']}) should be valid, got {$error}");
            expect(Gstin::stateCode($case['input']))->toBe($case['state_code']);
        } else {
            expect($error)->toBe($case['error'], "{$case['input']} ({$case['note']})");
            expect(Gstin::stateCode($case['input']))->toBeNull();
        }
    }
});

it('matches the state code table entry by entry', function () {
    $states = vector('gst-state-codes.json')['states'];

    expect(GstStateCodes::all())->toHaveCount(count($states));
    foreach ($states as $state) {
        expect(GstStateCodes::exists($state['code']))->toBeTrue()
            ->and(GstStateCodes::name($state['code']))->toBe($state['name'])
            ->and(GstStateCodes::isRetired($state['code']))->toBe($state['retired']);
    }
    foreach ([0, 39, 40, 98, 100] as $bad) {
        expect(GstStateCodes::exists($bad))->toBeFalse();
    }
    expect(array_keys(GstStateCodes::selectable()))->not->toContain(25, 28, 99)->toContain(27, 97);
});

it('runs every state-name lookup vector', function () {
    foreach (vector('gst-state-codes.json')['name_lookups'] as $lookup) {
        expect(GstStateCodes::codeForName($lookup['name']))->toBe($lookup['code'], "name '{$lookup['name']}'");
    }
});

it('normalises state codes to two digits', function () {
    expect(GstStateCodes::normalize(7))->toBe('07')->and(GstStateCodes::normalize('07'))->toBe('07')->and(GstStateCodes::normalize('27'))->toBe('27')
        ->and(GstStateCodes::normalize('0'))->toBeNull()->and(GstStateCodes::normalize('abc'))->toBeNull()->and(GstStateCodes::normalize(null))->toBeNull();
});

it('exposes the checksum as a function of the first 14 characters', function () {
    expect(Gstin::checkChar('27AAPFU0939F1Z'))->toBe('V');
});

it('works as a validation rule with readable messages', function () {
    $v = fn (mixed $value) => Validator::make(['g' => $value], ['g' => [new ValidGstin]]);

    expect($v('27AAPFU0939F1ZV')->passes())->toBeTrue();
    expect($v('27AAPFU0939F1ZW')->errors()->first('g'))->toContain('check character');
    expect($v('27AAPFU0939F1Z')->errors()->first('g'))->toContain('15 characters');
    expect($v(['x'])->fails())->toBeTrue();
});
