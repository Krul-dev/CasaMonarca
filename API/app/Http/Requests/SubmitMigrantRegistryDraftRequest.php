<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitMigrantRegistryDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $mimeTypes = implode(',', config('features.migrant_documents_allowed_mime_types', []));

        return [
            'payload_json' => ['required', 'array'],
            'documents' => config('features.migrant_documents', false)
                ? ['nullable', 'array', 'max:'.config('features.migrant_documents_max_per_entry', 10)]
                : ['prohibited'],
            'documents.*' => ['file', 'max:16384', "mimetypes:{$mimeTypes}"],
            'document_labels' => config('features.migrant_documents', false) ? ['nullable', 'array'] : ['prohibited'],
            'document_labels.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload_json');

        if (! is_string($payload)) {
            return;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (is_array($decoded)) {
            $this->merge(['payload_json' => $decoded]);
        }
    }
}
