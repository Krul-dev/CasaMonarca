<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;

class DocumentVerificationBundleService
{
    public function __construct(
        private readonly DocumentSignatureViewService $documentSignatureViewService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Document $document, ?DocumentRevision $revision): array
    {
        $signatures = $revision?->signatures ?? collect();

        return [
            'version' => 1,
            'exportedAt' => now()->utc()->toIso8601String(),
            'document' => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'owner' => $document->owner?->name,
            ],
            'revision' => [
                'id' => $revision?->getKey(),
                'number' => $revision?->revision_number,
                'fileName' => $revision?->original_file_name,
                'mimeType' => $revision?->mime_type,
                'sizeBytes' => $revision?->size_bytes,
                'sha256' => $revision?->sha256,
                'signatureStatus' => $revision?->signature_status ?? 'unsigned',
                'createdAt' => $revision?->created_at?->toIso8601String(),
                'createdBy' => $revision?->createdBy?->name,
            ],
            'signatures' => $signatures
                ->map(fn (DocumentSignature $signature): array => $this->documentSignatureViewService->toVerificationBundleSignature($signature))
                ->values(),
        ];
    }
}
