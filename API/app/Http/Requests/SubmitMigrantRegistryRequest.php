<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitMigrantRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signature_payload' => ['required', 'string'],
            'public_key_ref' => ['nullable', 'string'],
        ];
    }
}
