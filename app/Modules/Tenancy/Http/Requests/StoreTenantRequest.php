<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:120']];
    }
}
