<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentSigningIntentService;
use App\Services\Security\SecurityChallengeIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentSignOptionsController extends Controller
{
    private const SIGN_INTENT_KEY = 'documents.sign.webauthn.intent';

    private const SIGN_CHALLENGE_INTENT_ID_KEY = 'documents.sign.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSigningIntentService $documentSigningIntentService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): JsonResponse
    {
        if ($revision !== null) {
            abort_unless(
                (int) $revision->document_id === (int) $document->getKey(),
                404,
                'Selected document revision could not be found.',
            );
        } else {
            $document->load('currentRevision');
            $revision = $document->currentRevision;
            abort_unless($revision !== null, 404, 'Current document revision could not be found.');
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $this->documentAuthorizationService->canSignRevision($user, $document, $revision)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.sign', $document, $revision);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn signing origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Document signing requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $user->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for document signing.',
            ], 422);
        }

        $signIntent = $this->documentSigningIntentService->buildForRevision(
            $document,
            $revision,
            $user,
            $origin,
            $originHost,
        );
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'document.sign',
            challenge: $signIntent['challenge'],
            actor: $user,
            origin: $origin,
            rpId: $originHost,
            expiresAt: (string) $signIntent['intent']['expiresAt'],
            payload: $signIntent['intent'],
            targetType: 'document_revision',
            targetId: $revision->getKey(),
        );

        $request->session()->put([
            self::SIGN_INTENT_KEY => $signIntent['intent'],
            self::SIGN_CHALLENGE_INTENT_ID_KEY => $challengeIntent->getKey(),
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentSignatureChallengeStarted,
            $user,
            [
                'type' => 'document_revision',
                'id' => $revision->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision->getKey(),
            ],
            [
                'revisionNumber' => $revision->revision_number,
                'rpId' => $originHost,
                'challengeIntentId' => $challengeIntent->getKey(),
            ],
        );

        return response()->json([
            'message' => 'Document signature challenge created.',
            'options' => [
                'challenge' => $signIntent['challenge'],
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
            'signingTarget' => [
                'documentId' => $document->getKey(),
                'revisionId' => $signIntent['intent']['revisionId'],
                'revisionNumber' => $signIntent['intent']['revisionNumber'],
                'documentHash' => $signIntent['intent']['revisionSha256'],
                'expiresAt' => $signIntent['intent']['expiresAt'],
            ],
            'challengeIntent' => [
                'id' => $challengeIntent->getKey(),
                'purpose' => $challengeIntent->purpose,
                'status' => $challengeIntent->status,
                'expiresAt' => $challengeIntent->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
