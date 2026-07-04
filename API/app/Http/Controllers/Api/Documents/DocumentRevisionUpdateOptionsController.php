<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentRevisionUpdateIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentRevisionUpdateOptionsController extends Controller
{
    public const REVISION_UPDATE_INTENT_KEY = 'documents.revisions.update.webauthn.intent';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentRevisionUpdateIntentService $documentRevisionUpdateIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'originalFileName' => ['required', 'string', 'max:255'],
            'sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'sizeBytes' => ['required', 'integer', 'min:1', 'max:16777216'],
        ]);

        $document->load('currentRevision');
        abort_unless($document->currentRevision !== null, 404, 'Current document revision could not be found.');

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $document->isApproved() || ! $this->documentAuthorizationService->canUpdateDocument($user)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.update', $document);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn revision update origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Document revision updates require localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $user->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for document revision updates.',
            ], 422);
        }

        $updateIntent = $this->documentRevisionUpdateIntentService->buildForCurrentRevision(
            $document,
            $user,
            $origin,
            $originHost,
            [
                'originalFileName' => basename((string) $validated['originalFileName']),
                'sha256' => (string) $validated['sha256'],
                'sizeBytes' => (int) $validated['sizeBytes'],
            ],
        );

        $request->session()->put([
            self::REVISION_UPDATE_INTENT_KEY => $updateIntent['intent'],
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentRevisionChallengeStarted,
            $user,
            [
                'type' => 'document',
                'id' => $document->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $document->currentRevision?->getKey(),
            ],
            [
                'candidateOriginalFileName' => $updateIntent['intent']['candidateOriginalFileName'],
                'candidateSha256' => $updateIntent['intent']['candidateSha256'],
                'parentRevisionNumber' => $updateIntent['intent']['revisionNumber'],
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'Document revision update challenge created.',
            'options' => [
                'challenge' => $updateIntent['challenge'],
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials
                    ->map(fn ($credential) => [
                        'id' => $credential->credential_id,
                        'type' => 'public-key',
                        'transports' => $credential->transports,
                    ])
                    ->values(),
            ],
            'revisionTarget' => [
                'candidateHash' => $updateIntent['intent']['candidateSha256'],
                'candidateOriginalFileName' => $updateIntent['intent']['candidateOriginalFileName'],
                'documentId' => $document->getKey(),
                'parentRevisionId' => $updateIntent['intent']['revisionId'],
                'parentRevisionNumber' => $updateIntent['intent']['revisionNumber'],
                'parentRevisionHash' => $updateIntent['intent']['revisionSha256'],
                'expiresAt' => $updateIntent['intent']['expiresAt'],
            ],
        ]);
    }
}
