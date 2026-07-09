<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMigrantRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payload_json' => ['required', 'array'],
            'payload_json.attentionDate' => ['required', 'date', 'before_or_equal:today'],
            'payload_json.firstName' => ['required', 'string', 'max:120'],
            'payload_json.firstLastName' => ['required', 'string', 'max:120'],
            'payload_json.secondLastName' => ['nullable', 'string', 'max:120'],
            'payload_json.fullName' => ['nullable', 'string', 'max:255'],
            'payload_json.phone' => ['nullable', 'string', 'max:60'],
            'payload_json.countryOfOrigin' => ['required', 'string', 'max:120'],
            'payload_json.departmentState' => ['nullable', 'string', 'max:120'],
            'payload_json.civilStatus' => ['nullable', 'string', 'max:80'],
            'payload_json.birthDate' => ['required', 'date', 'before_or_equal:today'],
            'payload_json.gender' => ['required', 'string', 'max:80'],
            'payload_json.populationGroup' => ['required', 'string', 'max:120'],
            'payload_json.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
