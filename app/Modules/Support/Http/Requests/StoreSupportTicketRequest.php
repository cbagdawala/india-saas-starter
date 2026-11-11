<?php

namespace App\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }
}
