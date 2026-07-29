<?php

namespace App\Http\Requests;

class StoreMigrantRegistryRequest extends MigrantRegistryPayloadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...$this->documentRules(),
        ];
    }
}
