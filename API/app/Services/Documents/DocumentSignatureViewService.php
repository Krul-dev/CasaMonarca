<?php

namespace App\Services\Documents;

use App\Models\DocumentSignature;

class DocumentSignatureViewService
{
    public function __construct(
        private readonly DocumentSignatureExpiryService $documentSignatureExpiryService,
    ) {}

    public function toRevisionSignature(DocumentSignature $signature): array
    {
        return [
            'id' => $signature->getKey(),
            'signatureType' => $signature->signature_type,
            'verificationStatus' => $signature->verification_status,
            'documentHash' => $signature->signature_hash,
            'signedAt' => $signature->signed_at?->toIso8601String(),
            'expiresAt' => $this->documentSignatureExpiryService->resolveExpiresAt($signature)?->toIso8601String(),
            'signedBy' => $this->signedByPayload($signature),
        ];
    }

    public function toVerificationSignature(DocumentSignature $signature): array
    {
        return [
            ...$this->toRevisionSignature($signature),
            'credential' => $this->credentialPayload($signature),
        ];
    }

    public function toVerificationBundleSignature(DocumentSignature $signature): array
    {
        return [
            ...$this->toVerificationSignature($signature),
            'intent' => data_get($signature->metadata, 'intent'),
            'canonicalIntent' => data_get($signature->metadata, 'canonicalIntent'),
            'challenge' => data_get($signature->metadata, 'challenge'),
            'assertion' => data_get($signature->metadata, 'assertion'),
        ];
    }

    private function credentialPayload(DocumentSignature $signature): array
    {
        return [
            'id' => data_get($signature->metadata, 'credentialId'),
            'name' => data_get($signature->metadata, 'credentialName'),
            'publicKey' => data_get($signature->metadata, 'publicKey'),
            'publicKeyFormat' => data_get($signature->metadata, 'publicKeyFormat'),
            'publicKeyAlgorithm' => data_get($signature->metadata, 'publicKeyAlgorithm'),
            'publicKeyFingerprintSha256' => data_get($signature->metadata, 'publicKeyFingerprintSha256'),
            'signCount' => data_get($signature->metadata, 'signCount'),
        ];
    }

    private function signedByPayload(DocumentSignature $signature): array
    {
        return [
            'id' => $signature->signedBy?->getKey(),
            'name' => $signature->signedBy?->name,
            'email' => $signature->signedBy?->email,
            'role' => $signature->signedBy?->role?->value,
        ];
    }
}
