<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(TenantRole::class)]];
    }
}
