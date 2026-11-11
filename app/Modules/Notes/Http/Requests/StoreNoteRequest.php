<?php

namespace App\Modules\Notes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
