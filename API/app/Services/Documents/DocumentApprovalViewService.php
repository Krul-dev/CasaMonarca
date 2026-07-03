<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentSignatureRequirement;

class DocumentApprovalViewService
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'title' => $document->title,
            'status' => $document->status,
            'confidentiality' => $document->confidentiality,
            'signatureOrderEnforced' => (bool) $document->signature_order_enforced,
            'approvalNote' => $document->approval_note,
            'approvedAt' => $document->approved_at?->toIso8601String(),
            'uploadedBy' => [
                'id' => $document->uploadedBy?->getKey(),
                'name' => $document->uploadedBy?->name,
                'email' => $document->uploadedBy?->email,
                'role' => $document->uploadedBy?->role?->value,
            ],
            'approvedBy' => [
                'id' => $document->approvedBy?->getKey(),
                'name' => $document->approvedBy?->name,
                'email' => $document->approvedBy?->email,
                'role' => $document->approvedBy?->role?->value,
            ],
            'currentRevision' => $document->currentRevision ? [
                'id' => $document->currentRevision->getKey(),
                'revisionNumber' => $document->currentRevision->revision_number,
                'originalFileName' => $document->currentRevision->original_file_name,
                'mimeType' => $document->currentRevision->mime_type,
                'sizeBytes' => $document->currentRevision->size_bytes,
                'sha256' => $document->currentRevision->sha256,
                'signatureStatus' => $document->currentRevision->signature_status,
            ] : null,
            'signatureRequirements' => $document->signatureRequirements
                ->map(fn (DocumentSignatureRequirement $requirement): array => [
                    'id' => $requirement->getKey(),
                    'sequence' => $requirement->sequence,
                    'signerRole' => $requirement->signer_role?->value,
                    'signerUser' => [
                        'id' => $requirement->signerUser?->getKey(),
                        'name' => $requirement->signerUser?->name,
                        'email' => $requirement->signerUser?->email,
                        'role' => $requirement->signerUser?->role?->value,
                    ],
                    'fulfilledAt' => $requirement->fulfilled_at?->toIso8601String(),
                    'fulfilledBySignatureId' => $requirement->fulfilled_by_signature_id,
                ])
                ->values(),
            'createdAt' => $document->created_at?->toIso8601String(),
            'updatedAt' => $document->updated_at?->toIso8601String(),
        ];
    }
}
