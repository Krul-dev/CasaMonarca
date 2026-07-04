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

class DocumentIndexController extends Controller
{
    public function __construct(
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSignatureViewService $documentSignatureViewService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $documents = Document::query()
            ->with([
                'owner',
                'uploadedBy',
                'approvedBy',
                'currentRevision.signatures.signedBy',
                'signatureRequirements.signerUser',
            ])
            ->where('status', 'active')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Documents loaded successfully.',
            'documents' => $documents->map(function (Document $document) use ($user): array {
                return [
                    'id' => $document->getKey(),
                    'title' => $document->title,
                    'status' => $document->status,
                    'confidentiality' => $document->confidentiality,
                    'owner' => [
                        'id' => $document->owner?->getKey(),
                        'name' => $document->owner?->name,
                    ],
                    'uploadedBy' => [
                        'id' => $document->uploadedBy?->getKey(),
                        'name' => $document->uploadedBy?->name,
                    ],
                    'capabilities' => $this->documentAuthorizationService
                        ->documentCapabilities($user, $document),
                    'approval' => [
                        'approvedAt' => $document->approved_at?->toIso8601String(),
                        'approvedBy' => [
                            'id' => $document->approvedBy?->getKey(),
                            'name' => $document->approvedBy?->name,
                            'email' => $document->approvedBy?->email,
                            'role' => $document->approvedBy?->role?->value,
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
                    'currentRevision' => [
                        'id' => $document->currentRevision?->getKey(),
                        'revisionNumber' => $document->currentRevision?->revision_number,
                        'originalFileName' => $document->currentRevision?->original_file_name,
                        'mimeType' => $document->currentRevision?->mime_type,
                        'sizeBytes' => $document->currentRevision?->size_bytes,
                        'sha256' => $document->currentRevision?->sha256,
                        'signatureStatus' => $document->currentRevision?->signature_status,
                        'signatures' => $document->currentRevision?->signatures
                            ->map(fn (DocumentSignature $signature): array => $this->documentSignatureViewService->toRevisionSignature($signature))
                            ->values() ?? [],
                    ],
                    'createdAt' => $document->created_at?->toIso8601String(),
                    'updatedAt' => $document->updated_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }
}
