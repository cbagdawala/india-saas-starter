<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in([TenantRole::Admin->value, TenantRole::Member->value])],
        ];
    }
}
