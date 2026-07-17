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

class MigrantRegistryReviewVerifyController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $migrantRegistryService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /** @throws ValidationException */
    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $intent = $request->session()->get(MigrantRegistryReviewOptionsController::INTENT_KEY);

        if (! is_array($intent) || ($intent['purpose'] ?? null) !== 'migrant-registry-review') {
            return response()->json(['message' => 'Migrant review challenge was not initiated.'], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) ($intent['expiresAt'] ?? ''));
        } catch (\Throwable) {
            return response()->json(['message' => 'Migrant review challenge is invalid.'], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);
            return response()->json(['message' => 'Migrant review challenge expired. Request a new challenge.'], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User || (int) $actor->getKey() !== (int) ($intent['actorUserId'] ?? 0)) {
            return response()->json(['message' => 'Migrant review challenge does not match the authenticated session.'], 401);
        }

        $challengeIntent = $this->pendingChallengeIntent($request, $actor);

        if ($challengeIntent instanceof JsonResponse) {
            return $challengeIntent;
        }

        if (
            (int) $migrantRegistryEntry->getKey() !== (int) ($intent['entryId'] ?? 0) ||
            ! $this->canReview($actor) ||
            $migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_PENDING_REVIEW ||
            (string) ($intent['entryStatus'] ?? '') !== MigrantRegistryService::STATUS_PENDING_REVIEW ||
            ! is_string($intent['payloadHash'] ?? null) ||
            ! hash_equals((string) $intent['payloadHash'], hash('sha256', json_encode($this->reviewPayload($migrantRegistryEntry), JSON_THROW_ON_ERROR)))
        ) {
            $this->failChallenge($challengeIntent, 'entry_state_changed');
            $this->forgetChallenge($request);
            return response()->json(['message' => 'Migrant registration changed after the review challenge started. Reload and try again.'], 409);
        }

        if (
            $challengeIntent instanceof SecurityChallengeIntent &&
            (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) ($intent['challenge'] ?? ''))) ||
                (int) data_get($challengeIntent->payload, 'entryId') !== (int) $intent['entryId'] ||
                (string) data_get($challengeIntent->payload, 'decision') !== 'forward'
            )
        ) {
            $this->failChallenge($challengeIntent, 'intent_payload_mismatch');
            $this->forgetChallenge($request);
            return response()->json(['message' => 'Migrant review challenge is invalid.'], 401);
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

        $credential = $actor->webauthnCredentials()->where('credential_id', (string) $payload['id'])->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages(['id' => ['This security key is not registered to the current account.']]);
        }

        try {
            $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
                $payload,
                $credential,
                (string) $intent['challenge'],
                (string) $intent['origin'],
                (string) $intent['rpId'],
            );
        } catch (ValidationException $exception) {
            $this->failChallenge($challengeIntent, 'assertion_validation_failed');
            $this->forgetChallenge($request);
            throw $exception;
        }

        $credential->forceFill(['sign_count' => $newSignCount, 'last_used_at' => now()])->save();
        $entry = $this->migrantRegistryService->forwardReview(
            $actor,
            $migrantRegistryEntry,
            is_string($intent['reason'] ?? null) ? $intent['reason'] : null,
            [
                'credentialId' => $credential->credential_id,
                'credentialName' => $credential->name,
                'signCount' => $newSignCount,
                'challengeIntentId' => $challengeIntent?->getKey(),
                'intent' => [...$intent, 'challenge' => null, 'challengeRedacted' => true],
                'assertion' => $payload,
            ],
        );

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->forgetChallenge($request);

        return response()->json([
            'message' => 'Migrant registration forwarded for coordinator approval.',
            'data' => $entry,
        ]);
    }

    private function pendingChallengeIntent(Request $request, User $actor): SecurityChallengeIntent|JsonResponse|null
    {
        $challengeIntentId = $request->session()->get(MigrantRegistryReviewOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (! is_string($challengeIntentId) || $challengeIntentId === '') {
            return null;
        }

        $challengeIntent = $this->securityChallengeIntentService->findPendingForActor($challengeIntentId, $actor, 'migrant.registry.review');

        if (! $challengeIntent instanceof SecurityChallengeIntent) {
            $this->forgetChallenge($request);
            return response()->json(['message' => 'Migrant review challenge is no longer pending.'], 401);
        }

        if ($challengeIntent->expires_at?->isPast()) {
            $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
            $this->forgetChallenge($request);
            return response()->json(['message' => 'Migrant review challenge expired. Request a new challenge.'], 401);
        }

        return $challengeIntent;
    }

    private function canReview(User $actor): bool
    {
        return in_array($actor->role ?? UserRole::default(), [UserRole::Admin, UserRole::Coordinator, UserRole::NonCoordinator], true);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(MigrantRegistryEntry $entry): array
    {
        if ($entry->pending_action === MigrantRegistryService::ACTION_UPDATE && is_array($entry->pending_payload_json)) {
            return $entry->pending_payload_json;
        }

        return is_array($entry->payload_json) ? $entry->payload_json : [];
    }

    private function failChallenge(?SecurityChallengeIntent $challengeIntent, string $reason): void
    {
        if ($challengeIntent instanceof SecurityChallengeIntent && $challengeIntent->isPending()) {
            $this->securityChallengeIntentService->markFailed($challengeIntent, $reason);
        }
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            MigrantRegistryReviewOptionsController::INTENT_KEY,
            MigrantRegistryReviewOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }
}
