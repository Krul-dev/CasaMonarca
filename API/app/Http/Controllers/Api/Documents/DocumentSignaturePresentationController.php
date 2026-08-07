<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentSignaturePresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class DocumentSignaturePresentationController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSignaturePresentationService $documentSignaturePresentationService,
    ) {}

    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): Response|JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate(['locale' => ['nullable', 'in:en,es']]);
        $locale = (string) ($validated['locale'] ?? 'es');

        if ($revision !== null) {
            abort_unless((int) $revision->document_id === (int) $document->getKey(), 404, 'Selected document revision could not be found.');
        } else {
            $document->load('currentRevision');
            $revision = $document->currentRevision;
            abort_unless($revision !== null, 404, 'Current document revision could not be found.');
        }

        if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
            return $this->documentAuthorizationService->forbiddenResponse(
                $request,
                $user,
                'document.signature_presentation.read',
                $document,
                $revision,
            );
        }

        if (strtolower((string) $revision->mime_type) !== 'application/pdf') {
            return response()->json(['message' => 'A signature presentation can only be generated for PDF revisions.'], 415);
        }

        $revision->load(['signatureRequirements', 'signatures.signedBy']);

        if ($revision->signatures->isEmpty()) {
            return response()->json(['message' => 'This revision does not have signatures to present.'], 409);
        }

        abort_unless(
            Storage::disk($revision->storage_disk)->exists($revision->storage_path),
            404,
            'Document revision file could not be found.',
        );

        $presentation = $this->documentSignaturePresentationService->generate($document, $revision, $locale);

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentSignaturePresentationDownloaded,
            $user,
            [
                'type' => 'document_revision',
                'id' => $revision->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision->getKey(),
            ],
            [
                'fallbackReason' => $presentation['fallbackReason'],
                'locale' => $locale,
                'presentationMode' => $presentation['mode'],
                'revisionNumber' => $revision->revision_number,
                'signatureCount' => $revision->signatures->count(),
            ],
        );

        return response($presentation['contents'], 200, [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $presentation['fileName']),
            'Content-Type' => 'application/pdf',
            'X-CasaMonarca-Presentation-Mode' => $presentation['mode'],
        ]);
    }
}
