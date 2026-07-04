<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentVerificationBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentVerificationBundleController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentVerificationBundleService $documentVerificationBundleService,
    ) {}

    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($revision !== null) {
            abort_unless(
                (int) $revision->document_id === (int) $document->getKey(),
                404,
                'Selected document revision could not be found.',
            );

            if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'history.read',
                    $document,
                    $revision,
                );
            }

            $document->load('owner');
            $revision->load(['createdBy', 'signatures.signedBy']);
        } else {
            $document->load('owner');
            $document->load([
                'currentRevision.createdBy',
                'currentRevision.signatures.signedBy',
            ]);

            $revision = $document->currentRevision;

            if ($revision === null || ! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'document.verification_bundle.read',
                    $document,
                    $revision,
                );
            }
        }

        $signatures = $revision?->signatures ?? collect();

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentVerificationBundleDownloaded,
            $user,
            [
                'type' => $revision instanceof DocumentRevision ? 'document_revision' : 'document',
                'id' => $revision?->getKey() ?? $document->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision?->getKey(),
            ],
            [
                'revisionNumber' => $revision?->revision_number,
                'signatureCount' => $signatures->count(),
            ],
        );

        return response()->json([
            'message' => 'Document verification bundle loaded successfully.',
            'bundle' => $this->documentVerificationBundleService->build($document, $revision),
        ]);
    }
}
