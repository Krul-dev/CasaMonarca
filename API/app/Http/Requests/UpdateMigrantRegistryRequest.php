<?php

namespace App\Http\Requests;

class UpdateMigrantRegistryRequest extends MigrantRegistryPayloadRequest
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
