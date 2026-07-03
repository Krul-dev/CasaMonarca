<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentDeleteOptionsController extends Controller
{
    private const CHALLENGE_KEY = 'documents.delete.webauthn.challenge';

    private const ORIGIN_KEY = 'documents.delete.webauthn.origin';

    private const RP_ID_KEY = 'documents.delete.webauthn.rp_id';

    private const USER_ID_KEY = 'documents.delete.webauthn.user_id';

    private const DOCUMENT_ID_KEY = 'documents.delete.webauthn.document_id';

    private const CHALLENGE_INTENT_ID_KEY = 'documents.delete.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
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

        if (! $this->documentAuthorizationService->canDeleteDocument($user)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.delete', $document);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn deletion origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Document deletion requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $user->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for document deletion.',
            ], 422);
        }

        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $expiresAt = CarbonImmutable::now('UTC')->addMinute();
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'document.delete',
            challenge: $challenge,
            actor: $user,
            origin: $origin,
            rpId: $originHost,
            expiresAt: $expiresAt,
            payload: [
                'action' => 'document.delete',
                'documentId' => (int) $document->getKey(),
                'documentTitle' => $document->title,
                'origin' => $origin,
                'rpId' => $originHost,
                'userId' => (int) $user->getKey(),
                'version' => 1,
            ],
            targetType: 'document',
            targetId: $document->getKey(),
        );

        $request->session()->put([
            self::CHALLENGE_KEY => $challenge,
            self::ORIGIN_KEY => $origin,
            self::RP_ID_KEY => $originHost,
            self::USER_ID_KEY => $user->getKey(),
            self::DOCUMENT_ID_KEY => $document->getKey(),
            self::CHALLENGE_INTENT_ID_KEY => $challengeIntent->getKey(),
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentDeleteChallengeStarted,
            $user,
            [
                'type' => 'document',
                'id' => $document->getKey(),
                'documentId' => $document->getKey(),
            ],
            [
                'action' => 'document.delete',
                'challengeIntentId' => $challengeIntent->getKey(),
                'purpose' => 'document.delete',
                'rpId' => $originHost,
                'title' => $document->title,
            ],
        );

        return response()->json([
            'message' => 'Document deletion challenge created.',
            'options' => [
                'challenge' => $challenge,
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
            'challengeIntent' => [
                'id' => $challengeIntent->getKey(),
                'purpose' => $challengeIntent->purpose,
                'status' => $challengeIntent->status,
                'expiresAt' => $challengeIntent->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
