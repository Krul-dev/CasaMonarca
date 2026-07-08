<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveArcoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string'],
            'needs_admin_deletion' => ['nullable', 'boolean'],
        ];
    }
}
