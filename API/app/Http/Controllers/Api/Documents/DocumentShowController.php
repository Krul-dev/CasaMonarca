<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentSignatureViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentShowController extends Controller
{
    public function __construct(
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSignatureViewService $documentSignatureViewService,
    ) {}

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $document->load([
            'owner',
            'uploadedBy',
            'approvedBy',
            'signatureRequirements.signerUser',
            'currentRevision.createdBy',
            'currentRevision.signatures.signedBy',
            'revisions.createdBy',
            'revisions.signatures.signedBy',
        ]);

        if (! $this->documentAuthorizationService->canReadDocument($user, $document)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.read', $document);
        }

        $documentCapabilities = $this->documentAuthorizationService
            ->documentCapabilities($user, $document);

        return response()->json([
            'message' => 'Document loaded successfully.',
            'document' => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'status' => $document->status,
                'confidentiality' => $document->confidentiality,
                'owner' => [
                    'id' => $document->owner?->getKey(),
                    'name' => $document->owner?->name,
                    'email' => $document->owner?->email,
                ],
                'uploadedBy' => [
                    'id' => $document->uploadedBy?->getKey(),
                    'name' => $document->uploadedBy?->name,
                    'email' => $document->uploadedBy?->email,
                ],
                'capabilities' => $documentCapabilities,
                'approval' => [
                    'approvedAt' => $document->approved_at?->toIso8601String(),
                    'approvedBy' => [
                        'id' => $document->approvedBy?->getKey(),
                        'name' => $document->approvedBy?->name,
                        'email' => $document->approvedBy?->email,
                    ],
                    'note' => $document->approval_note,
                    'signatureOrderEnforced' => (bool) $document->signature_order_enforced,
                    'signatureRequirements' => $document->signatureRequirements
                        ->map(fn ($requirement): array => [
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
                ],
                'currentRevision' => $document->currentRevision ? [
                    'id' => $document->currentRevision->getKey(),
                    'revisionNumber' => $document->currentRevision->revision_number,
                    'originalFileName' => $document->currentRevision->original_file_name,
                    'mimeType' => $document->currentRevision->mime_type,
                    'sizeBytes' => $document->currentRevision->size_bytes,
                    'sha256' => $document->currentRevision->sha256,
                    'signatureStatus' => $document->currentRevision->signature_status,
                    'createdBy' => [
                        'id' => $document->currentRevision->createdBy?->getKey(),
                        'name' => $document->currentRevision->createdBy?->name,
                        'email' => $document->currentRevision->createdBy?->email,
                    ],
                    'createdAt' => $document->currentRevision->created_at?->toIso8601String(),
                ] : null,
                'revisions' => $this->documentAuthorizationService
                    ->visibleRevisions($user, $document, $document->revisions)
                    ->sortByDesc('revision_number')
                    ->values()
                    ->map(function ($revision) use ($document, $user): array {
                        return [
                            'id' => $revision->getKey(),
                            'parentRevisionId' => $revision->parent_revision_id,
                            'revisionNumber' => $revision->revision_number,
                            'originalFileName' => $revision->original_file_name,
                            'mimeType' => $revision->mime_type,
                            'sizeBytes' => $revision->size_bytes,
                            'sha256' => $revision->sha256,
                            'signatureStatus' => $revision->signature_status,
                            'capabilities' => $this->documentAuthorizationService
                                ->revisionCapabilities($user, $document, $revision),
                            'signatures' => $revision->signatures
                                ->map(fn (DocumentSignature $signature): array => $this->documentSignatureViewService->toRevisionSignature($signature))
                                ->values(),
                            'diffMetadata' => $revision->diff_metadata,
                            'createdBy' => [
                                'id' => $revision->createdBy?->getKey(),
                                'name' => $revision->createdBy?->name,
                            ],
                            'createdAt' => $revision->created_at?->toIso8601String(),
                        ];
                    }),
                'createdAt' => $document->created_at?->toIso8601String(),
                'updatedAt' => $document->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
