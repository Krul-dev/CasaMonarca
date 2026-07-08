<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateArcoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registry_entry_id' => ['required', 'integer', 'exists:migrant_registry_entries,id'],
            'request_type' => ['required', 'string'],
            'reason' => ['required', 'string'],
        ];
    }
}
