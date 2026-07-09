<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MigrantRegistryApprovalVerifyController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $migrantRegistryService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $pendingIntent = $request->session()->get(MigrantRegistryApprovalOptionsController::INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ($pendingIntent['purpose'] ?? null) !== 'migrant-registry-approval' ||
            ! is_numeric($pendingIntent['actorUserId'] ?? null) ||
            ! is_numeric($pendingIntent['entryId'] ?? null) ||
            ! is_string($pendingIntent['entryStatus'] ?? null) ||
            ! is_string($pendingIntent['payloadHash'] ?? null) ||
            ! is_string($pendingIntent['decision'] ?? null) ||
            ! is_string($pendingIntent['challenge'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null)
        ) {
            return response()->json([
                'message' => 'Migrant approval challenge was not initiated.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Migrant approval challenge is invalid.'], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant approval challenge expired. Request a new challenge.',
            ], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User || (int) $actor->getKey() !== (int) $pendingIntent['actorUserId']) {
            return response()->json([
                'message' => 'Migrant approval challenge does not match the authenticated session.',
            ], 401);
        }

        $challengeIntent = $this->pendingChallengeIntent($request, $actor);

        if ($challengeIntent instanceof JsonResponse) {
            return $challengeIntent;
        }

        if ((int) $migrantRegistryEntry->getKey() !== (int) $pendingIntent['entryId']) {
            $this->markChallengeFailed($challengeIntent, 'entry_mismatch');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant approval challenge does not match the selected registration.',
            ], 401);
        }

        if (! $this->canApprove($actor, $migrantRegistryEntry)) {
            $this->markChallengeFailed($challengeIntent, 'actor_cannot_approve');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'This migrant registration cannot be approved by the current account.',
            ], 403);
        }

        if (
            $migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_PENDING_APPROVAL ||
            (string) $pendingIntent['entryStatus'] !== MigrantRegistryService::STATUS_PENDING_APPROVAL ||
            ! hash_equals(
                (string) $pendingIntent['payloadHash'],
                hash('sha256', json_encode($migrantRegistryEntry->payload_json, JSON_THROW_ON_ERROR)),
            )
        ) {
            $this->markChallengeFailed($challengeIntent, 'entry_state_changed');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant registration changed after the approval challenge started. Reload and try again.',
            ], 409);
        }

        if (
            $challengeIntent instanceof SecurityChallengeIntent &&
            (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) $pendingIntent['challenge'])) ||
                (int) data_get($challengeIntent->payload, 'entryId') !== (int) $pendingIntent['entryId'] ||
                (string) data_get($challengeIntent->payload, 'decision') !== (string) $pendingIntent['decision']
            )
        ) {
            $this->markChallengeFailed($challengeIntent, 'intent_payload_mismatch');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant approval challenge is invalid.',
            ], 401);
        }

        $payload = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['nullable', 'string'],
        ]);

        $credential = $actor->webauthnCredentials()
            ->where('credential_id', (string) $payload['id'])
            ->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered to the current account.'],
            ]);
        }

        try {
            $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
                $payload,
                $credential,
                (string) $pendingIntent['challenge'],
                (string) $pendingIntent['origin'],
                (string) $pendingIntent['rpId'],
            );
        } catch (ValidationException $exception) {
            $this->markChallengeFailed($challengeIntent, 'assertion_validation_failed');
            $this->forgetChallenge($request);

            throw $exception;
        }

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        $entry = $this->migrantRegistryService->resolveApproval(
            $actor,
            $migrantRegistryEntry,
            (string) $pendingIntent['decision'],
            is_string($pendingIntent['reason'] ?? null) ? (string) $pendingIntent['reason'] : null,
            [
                'credentialId' => $credential->credential_id,
                'credentialName' => $credential->name,
                'signCount' => $newSignCount,
                'challengeIntentId' => $challengeIntent?->getKey(),
                'intent' => [
                    ...$pendingIntent,
                    'challenge' => null,
                    'challengeRedacted' => true,
                ],
                'assertion' => $payload,
            ],
        );

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->forgetChallenge($request);

        return response()->json([
            'message' => $pendingIntent['decision'] === 'approve'
                ? 'Migrant registration approved.'
                : 'Migrant registration rejected.',
            'data' => $entry,
            'challengeIntent' => $challengeIntent instanceof SecurityChallengeIntent
                ? [
                    'id' => $challengeIntent->getKey(),
                    'purpose' => $challengeIntent->purpose,
                    'status' => $challengeIntent->status,
                    'expiresAt' => $challengeIntent->expires_at?->toIso8601String(),
                ]
                : null,
        ]);
    }

    private function pendingChallengeIntent(Request $request, User $actor): SecurityChallengeIntent|JsonResponse|null
    {
        $challengeIntentId = $request->session()->get(MigrantRegistryApprovalOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (! is_string($challengeIntentId) || $challengeIntentId === '') {
            return null;
        }

        $challengeIntent = $this->securityChallengeIntentService->findPendingForActor(
            $challengeIntentId,
            $actor,
            'migrant.registry.approval',
        );

        if (! $challengeIntent instanceof SecurityChallengeIntent) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant approval challenge is no longer pending.',
            ], 401);
        }

        if ($challengeIntent->expires_at?->isPast()) {
            $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Migrant approval challenge expired. Request a new challenge.',
            ], 401);
        }

        return $challengeIntent;
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

    private function markChallengeFailed(?SecurityChallengeIntent $challengeIntent, string $reason): void
    {
        if ($challengeIntent instanceof SecurityChallengeIntent && $challengeIntent->isPending()) {
            $this->securityChallengeIntentService->markFailed($challengeIntent, $reason);
        }
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            MigrantRegistryApprovalOptionsController::INTENT_KEY,
            MigrantRegistryApprovalOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }
}
