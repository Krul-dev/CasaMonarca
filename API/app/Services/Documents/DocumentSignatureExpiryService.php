<?php

namespace App\Services\Documents;

use App\Models\DocumentSignature;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DocumentSignatureExpiryService
{
    public function policyValidityDays(): int
    {
        return (int) config('documents.signature_validity_days', 365);
    }

    public function buildValidityMetadata(CarbonInterface $signedAt): array
    {
        return [
            'days' => $this->policyValidityDays(),
            'expiresAt' => $this->expiresAtFromSignedAt($signedAt)->toIso8601String(),
            'source' => 'server-policy',
        ];
    }

    public function expiresAtFromSignedAt(CarbonInterface $signedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($signedAt)
            ->setTimezone('UTC')
            ->addDays($this->policyValidityDays());
    }

    public function resolveExpiresAt(DocumentSignature $signature): ?CarbonImmutable
    {
        $storedExpiresAt = data_get($signature->metadata, 'validity.expiresAt');

        if (is_string($storedExpiresAt)) {
            try {
                return CarbonImmutable::parse($storedExpiresAt)->setTimezone('UTC');
            } catch (\Throwable) {
                // Fall back to the legacy signed_at-based policy below.
            }
        }

        if (! $signature->signed_at instanceof CarbonInterface) {
            return null;
        }

        return $this->expiresAtFromSignedAt($signature->signed_at);
    }
}
