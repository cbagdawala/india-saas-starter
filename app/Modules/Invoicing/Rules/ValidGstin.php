<?php

namespace App\Modules\Invoicing\Rules;

use App\Modules\Invoicing\Support\Gstin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidGstin implements ValidationRule
{
    private const MESSAGES = [
        'length' => 'A GSTIN has 15 characters.',
        'invalid_character' => 'A GSTIN uses only digits and capital letters.',
        'invalid_state_code' => 'The first two digits of the GSTIN are not a valid state code.',
        'invalid_pan' => 'The GSTIN does not contain a valid PAN.',
        'invalid_entity' => 'The 13th character of the GSTIN is not valid.',
        'invalid_z' => 'The 14th character of a GSTIN must be Z.',
        'checksum_mismatch' => 'The GSTIN check character does not match. Check for a typing mistake.',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = is_string($value) ? Gstin::error($value) : 'length';

        if ($error !== null) {
            $fail(self::MESSAGES[$error]);
        }
    }
}
