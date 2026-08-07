<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryPdfService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryPdfDownloadOptionsController extends Controller
{
    public const INTENT_KEY = 'registry.migrants.pdf.download.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'registry.migrants.pdf.download.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly MigrantRegistryPdfService $pdfService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->role === UserRole::Admin, 403);
        abort_if($migrantRegistryEntry->current_status === MigrantRegistryService::STATUS_DRAFT, 404);

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null || $this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Registry PDF download requires localhost or a domain name, not an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'Register a security key before downloading migrant registration PDFs.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $intent = [
            'version' => 1,
            'purpose' => 'migrant-registry-pdf-download',
            'actorUserId' => (int) $actor->getKey(),
            'entryId' => (int) $migrantRegistryEntry->getKey(),
            'registryStateHash' => $this->pdfService->stateHash($migrantRegistryEntry),
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'migrant.registry.pdf.download',
            challenge: $challenge,
            actor: $actor,
            origin: $origin,
            rpId: $originHost,
            expiresAt: $expiresAt,
            payload: [...$intent, 'challenge' => null, 'challengeRedacted' => true],
            targetType: 'migrant_registry_entry',
            targetId: $migrantRegistryEntry->getKey(),
        );

        $request->session()->put([
            self::INTENT_KEY => $intent,
            self::CHALLENGE_INTENT_ID_KEY => $challengeIntent->getKey(),
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::MigrantRegistryPdfDownloadChallengeStarted,
            $actor,
            ['type' => MigrantRegistryEntry::class, 'id' => $migrantRegistryEntry->getKey()],
            ['challengeIntentId' => $challengeIntent->getKey()],
        );

        return response()->json([
            'message' => 'Migrant registration PDF download challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials->map(fn ($credential) => [
                    'id' => $credential->credential_id,
                    'type' => 'public-key',
                    'transports' => $credential->transports,
                ])->values(),
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
