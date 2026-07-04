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

class DocumentVerificationController extends Controller
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
            'currentRevision.signatures.signedBy',
        ]);

        $revision = $document->currentRevision;

        if ($revision === null || ! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.verification.read', $document, $revision);
        }

        $signatures = $revision?->signatures ?? collect();
        $hasSignatures = $signatures->isNotEmpty();
        $verified = $hasSignatures
            && $signatures->every(
                fn (DocumentSignature $signature): bool => $signature->verification_status === 'verified',
            );

        return response()->json([
            'message' => 'Document verification state loaded successfully.',
            'verification' => [
                'documentId' => $document->getKey(),
                'currentRevisionId' => $revision?->getKey(),
                'currentRevisionNumber' => $revision?->revision_number,
                'signatureStatus' => $revision?->signature_status ?? 'unsigned',
                'hasSignatures' => $hasSignatures,
                'verified' => $verified,
                'signatures' => $signatures
                    ->map(fn (DocumentSignature $signature): array => $this->documentSignatureViewService->toVerificationSignature($signature))
                    ->values(),
            ],
        ]);
    }
}
