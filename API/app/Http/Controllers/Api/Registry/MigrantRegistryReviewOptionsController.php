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
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryReviewOptionsController extends Controller
{
    public const INTENT_KEY = 'registry.migrants.review.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'registry.migrants.review.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->canReview($actor)) {
            return response()->json(['message' => 'This account cannot review migrant registrations.'], 403);
        }

        if ($migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_PENDING_REVIEW) {
            return response()->json(['message' => 'This migrant registration is no longer pending review.'], 409);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null || $this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Migrant review requires localhost or a domain name, not an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'Register a security key before forwarding migrant registration reviews.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $payloadHash = hash('sha256', json_encode($this->reviewPayload($migrantRegistryEntry), JSON_THROW_ON_ERROR));
        $intent = [
            'version' => 1,
            'purpose' => 'migrant-registry-review',
            'actorUserId' => (int) $actor->getKey(),
            'entryId' => (int) $migrantRegistryEntry->getKey(),
            'entryStatus' => (string) $migrantRegistryEntry->current_status,
            'payloadHash' => $payloadHash,
            'decision' => 'forward',
            'reason' => $reason,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'migrant.registry.review',
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
            AuditEventType::MigrantRegistryReviewChallengeStarted,
            $actor,
            ['type' => MigrantRegistryEntry::class, 'id' => $migrantRegistryEntry->getKey()],
            ['challengeIntentId' => $challengeIntent->getKey(), 'reason' => $reason, 'rpId' => $originHost],
        );

        return response()->json([
            'message' => 'Migrant registration review challenge created.',
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

    private function canReview(User $actor): bool
    {
        return in_array($actor->role ?? UserRole::default(), [
            UserRole::Admin,
            UserRole::Coordinator,
            UserRole::NonCoordinator,
        ], true);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(MigrantRegistryEntry $entry): array
    {
        if ($entry->pending_action === MigrantRegistryService::ACTION_UPDATE && is_array($entry->pending_payload_json)) {
            return $entry->pending_payload_json;
        }

        return is_array($entry->payload_json) ? $entry->payload_json : [];
    }
}
