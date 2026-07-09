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
use Illuminate\Validation\Rule;

class MigrantRegistryApprovalOptionsController extends Controller
{
    public const INTENT_KEY = 'registry.migrants.approval.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'registry.migrants.approval.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->canApprove($actor, $migrantRegistryEntry)) {
            return response()->json([
                'message' => 'This migrant registration cannot be approved by the current account.',
            ], 403);
        }

        if ($migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_PENDING_APPROVAL) {
            return response()->json([
                'message' => 'This migrant registration is no longer pending approval.',
            ], 409);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json(['message' => 'WebAuthn approval origin is invalid.'], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Migrant approval requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for migrant approvals.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $decision = (string) $validated['decision'];
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $payloadHash = hash('sha256', json_encode($migrantRegistryEntry->payload_json, JSON_THROW_ON_ERROR));
        $intent = [
            'version' => 1,
            'purpose' => 'migrant-registry-approval',
            'actorUserId' => (int) $actor->getKey(),
            'entryId' => (int) $migrantRegistryEntry->getKey(),
            'entryStatus' => (string) $migrantRegistryEntry->current_status,
            'payloadHash' => $payloadHash,
            'decision' => $decision,
            'reason' => $reason,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'migrant.registry.approval',
            challenge: $challenge,
            actor: $actor,
            origin: $origin,
            rpId: $originHost,
            expiresAt: $expiresAt,
            payload: [
                ...$intent,
                'challenge' => null,
                'challengeRedacted' => true,
            ],
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
            AuditEventType::MigrantRegistryApprovalChallengeStarted,
            $actor,
            ['type' => MigrantRegistryEntry::class, 'id' => $migrantRegistryEntry->getKey()],
            [
                'challengeIntentId' => $challengeIntent->getKey(),
                'decision' => $decision,
                'reason' => $reason,
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'Migrant registration approval challenge created.',
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
            'approvalTarget' => [
                'entryId' => $migrantRegistryEntry->getKey(),
                'decision' => $decision,
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
            'challengeIntent' => [
                'id' => $challengeIntent->getKey(),
                'purpose' => $challengeIntent->purpose,
                'status' => $challengeIntent->status,
                'expiresAt' => $challengeIntent->expires_at?->toIso8601String(),
            ],
        ]);
    }

    private function canApprove(User $actor, MigrantRegistryEntry $entry): bool
    {
        $role = $actor->role ?? UserRole::default();

        if (! in_array($role, [UserRole::Admin, UserRole::Coordinator], true)) {
            return false;
        }

        if ($role === UserRole::Admin) {
            return true;
        }

        return (int) $entry->created_by !== (int) $actor->getKey();
    }
}
