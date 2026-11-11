<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:48']];
    }
}
