<?php

namespace App\Modules\Invoicing\Http\Requests;

use App\Modules\Invoicing\Rules\ValidGstin;
use App\Modules\Invoicing\Support\Gstin;
use App\Modules\Invoicing\Support\GstStateCodes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $data = array_map($clean, $this->only(['legal_name', 'address_line1', 'address_line2', 'city', 'pincode', 'country', 'state_code', 'gstin']));

        $data['country'] = strtoupper((string) ($data['country'] ?? 'IN'));
        $data['gstin'] = isset($data['gstin']) ? strtoupper($data['gstin']) : null;
        if (isset($data['state_code']) && ($normalised = GstStateCodes::normalize($data['state_code'])) !== null) {
            $data['state_code'] = $normalised;
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $india = $this->input('country') === 'IN';

        return [
            'legal_name' => ['required', 'string', 'max:150'],
            'address_line1' => ['required', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:80'],
            'pincode' => [$india ? 'required' : 'nullable', 'string', $india ? 'regex:/^[1-9][0-9]{5}$/' : 'max:10'],
            'country' => ['required', 'string', 'size:2', 'alpha:ascii'],
            'state_code' => [$india ? 'required' : 'nullable', 'string', Rule::in(array_map(fn (int $c) => str_pad((string) $c, 2, '0', STR_PAD_LEFT), array_keys(GstStateCodes::selectable())))],
            'gstin' => ['nullable', 'string', new ValidGstin],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $gstin = $this->input('gstin');
            if ($gstin === null || $validator->errors()->has('gstin')) {
                return;
            }
            if ($this->input('country') !== 'IN') {
                $validator->errors()->add('gstin', 'A GSTIN can only be used with an Indian address.');
            } elseif ($this->input('state_code') !== null && substr($gstin, 0, 2) !== $this->input('state_code')) {
                $validator->errors()->add('state_code', 'The state must match the first two digits of the GSTIN ('.substr($gstin, 0, 2).').');
            } elseif (! Gstin::isValid($gstin)) {
                $validator->errors()->add('gstin', 'Invalid GSTIN.');
            }
        }];
    }
}
