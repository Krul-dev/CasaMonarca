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

class MigrantRegistryBulkApprovalOptionsController extends Controller
{
    public const INTENT_KEY = 'registry.migrants.bulk_approval.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'registry.migrants.bulk_approval.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly MigrantRegistryService $migrantRegistryService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1', 'max:100'],
            'entry_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($actor->role ?? UserRole::default(), [UserRole::Admin, UserRole::Coordinator], true)) {
            return response()->json(['message' => 'This account cannot approve migrant registrations.'], 403);
        }

        $entryIds = collect($validated['entry_ids'])
            ->map(fn (mixed $entryId): int => (int) $entryId)
            ->sort()
            ->values();
        $entries = MigrantRegistryEntry::query()
            ->whereKey($entryIds->all())
            ->orderBy('id')
            ->get();

        if ($entries->count() !== $entryIds->count()) {
            return response()->json([
                'message' => 'One or more selected registrations are no longer available.',
            ], 422);
        }

        if ($entries->contains(fn (MigrantRegistryEntry $entry): bool => $entry->current_status !== MigrantRegistryService::STATUS_PENDING_APPROVAL)) {
            return response()->json([
                'message' => 'One or more selected registrations are no longer pending final approval.',
            ], 409);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json(['message' => 'WebAuthn approval origin is invalid.'], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Bulk approval requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for migrant approvals.',
            ], 422);
        }

        $targets = $entries->map(fn (MigrantRegistryEntry $entry): array => [
            'id' => (int) $entry->getKey(),
            'status' => (string) $entry->current_status,
            'pendingAction' => (string) ($entry->pending_action ?? MigrantRegistryService::ACTION_CREATE),
            'payloadHash' => $this->migrantRegistryService->approvalPayloadHash($entry),
        ])->values()->all();
        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $intent = [
            'version' => 1,
            'purpose' => 'migrant-registry-bulk-approval',
            'actorUserId' => (int) $actor->getKey(),
            'decision' => 'approve',
            'targets' => $targets,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'migrant.registry.bulk_approval',
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
            targetType: 'migrant_registry_batch',
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
            ['type' => 'migrant_registry_batch'],
            [
                'bulkApproval' => true,
                'challengeIntentId' => $challengeIntent->getKey(),
                'entryCount' => count($targets),
                'entryIds' => $entryIds->all(),
                'decision' => 'approve',
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'Bulk migrant approval challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials
                    ->map(fn ($credential): array => [
                        'id' => $credential->credential_id,
                        'type' => 'public-key',
                        'transports' => $credential->transports,
                    ])
                    ->values(),
            ],
            'approvalTarget' => [
                'entryIds' => $entryIds->all(),
                'entryCount' => count($targets),
                'decision' => 'approve',
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
}
