<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Carbon\CarbonImmutable;
use RuntimeException;

class DocumentSigningIntentService
{
    public function __construct(private readonly Base64UrlService $base64UrlService) {}

    /**
     * @return array{
     *     challenge: string,
     *     intent: array<string, int|string>,
     *     canonicalIntent: string,
     *     revision: DocumentRevision,
     * }
     */
    public function buildForCurrentRevision(
        Document $document,
        User $user,
        string $origin,
        string $rpId,
    ): array {
        $document->loadMissing('currentRevision');

        $revision = $document->currentRevision;

        if (! $revision instanceof DocumentRevision) {
            throw new RuntimeException('Current document revision could not be found.');
        }

        return $this->buildForRevision($document, $revision, $user, $origin, $rpId);
    }

    /**
     * @return array{
     *     challenge: string,
     *     intent: array<string, int|string>,
     *     canonicalIntent: string,
     *     revision: DocumentRevision,
     * }
     */
    public function buildForRevision(
        Document $document,
        DocumentRevision $revision,
        User $user,
        string $origin,
        string $rpId,
    ): array {
        if ((int) $revision->document_id !== (int) $document->getKey()) {
            throw new RuntimeException('Selected revision does not belong to this document.');
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();

        $intent = [
            'documentId' => (int) $document->getKey(),
            'expiresAt' => $expiresAt->toIso8601String(),
            'issuedAt' => $issuedAt->toIso8601String(),
            'nonce' => $this->base64UrlService->encode(random_bytes(32)),
            'origin' => $origin,
            'purpose' => 'document-sign',
            'revisionId' => (int) $revision->getKey(),
            'revisionNumber' => (int) $revision->revision_number,
            'revisionSha256' => (string) $revision->sha256,
            'rpId' => $rpId,
            'userId' => (int) $user->getKey(),
            'version' => 1,
        ];

        $canonicalIntent = $this->toCanonicalJson($intent);

        return [
            'challenge' => $this->base64UrlService->encode(hash('sha256', $canonicalIntent, true)),
            'intent' => $intent,
            'canonicalIntent' => $canonicalIntent,
            'revision' => $revision,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public function deriveChallenge(array $intent): string
    {
        return $this->base64UrlService->encode(
            hash('sha256', $this->toCanonicalJson($intent), true),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toCanonicalJson(array $payload): string
    {
        return json_encode(
            $this->normalizeValue($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->normalizeValue($nestedValue);
        }

        return $value;
    }
}
