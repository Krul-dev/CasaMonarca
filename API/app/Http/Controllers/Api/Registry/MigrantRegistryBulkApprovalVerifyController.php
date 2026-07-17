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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MigrantRegistryBulkApprovalVerifyController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $migrantRegistryService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $intent = $request->session()->get(MigrantRegistryBulkApprovalOptionsController::INTENT_KEY);

        if (! $this->isValidIntent($intent)) {
            return response()->json(['message' => 'Bulk migrant approval challenge was not initiated.'], 409);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $intent['expiresAt']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Bulk migrant approval challenge is invalid.'], 409);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Bulk migrant approval challenge expired. Request a new challenge.'], 409);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $actor->getKey() !== (int) $intent['actorUserId']) {
            return response()->json(['message' => 'Bulk approval challenge does not match the authenticated session.'], 409);
        }

        if (! in_array($actor->role ?? UserRole::default(), [UserRole::Admin, UserRole::Coordinator], true)) {
            return response()->json(['message' => 'This account cannot approve migrant registrations.'], 403);
        }

        $challengeIntent = $this->pendingChallengeIntent($request, $actor);

        if ($challengeIntent instanceof JsonResponse) {
            return $challengeIntent;
        }

        if (
            $challengeIntent instanceof SecurityChallengeIntent &&
            (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) $intent['challenge'])) ||
                (string) data_get($challengeIntent->payload, 'decision') !== 'approve' ||
                ! hash_equals(
                    MigrantRegistryService::approvalTargetsHash(data_get($challengeIntent->payload, 'targets')),
                    MigrantRegistryService::approvalTargetsHash($intent['targets']),
                )
            )
        ) {
            $this->failChallenge($challengeIntent, 'intent_payload_mismatch');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Bulk migrant approval challenge is invalid.'], 409);
        }

        $targets = collect($intent['targets'])->map(fn (array $target): array => [
            'id' => (int) $target['id'],
            'payloadHash' => (string) $target['payloadHash'],
        ])->values()->all();
        $entries = MigrantRegistryEntry::query()
            ->whereKey(collect($targets)->pluck('id')->all())
            ->get()
            ->keyBy(fn (MigrantRegistryEntry $entry): int => (int) $entry->getKey());

        foreach ($targets as $target) {
            $entry = $entries->get($target['id']);

            if (
                ! $entry instanceof MigrantRegistryEntry ||
                $entry->current_status !== MigrantRegistryService::STATUS_PENDING_APPROVAL ||
                ! hash_equals($target['payloadHash'], $this->migrantRegistryService->approvalPayloadHash($entry))
            ) {
                $this->failChallenge($challengeIntent, 'entry_state_changed');
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'One or more registrations changed after bulk approval started. Reload and try again.',
                ], 409);
            }
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
                (string) $intent['challenge'],
                (string) $intent['origin'],
                (string) $intent['rpId'],
            );
        } catch (ValidationException $exception) {
            $this->failChallenge($challengeIntent, 'assertion_validation_failed');
            $this->forgetChallenge($request);

            throw $exception;
        }

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        try {
            $resolvedEntries = $this->migrantRegistryService->resolveBulkApproval(
                $actor,
                $targets,
                [
                    'bulkApproval' => true,
                    'credentialId' => $credential->credential_id,
                    'credentialName' => $credential->name,
                    'signCount' => $newSignCount,
                    'challengeIntentId' => $challengeIntent?->getKey(),
                    'intent' => [...$intent, 'challenge' => null, 'challengeRedacted' => true],
                    'assertion' => $payload,
                ],
            );
        } catch (HttpExceptionInterface $exception) {
            $this->failChallenge($challengeIntent, 'entry_state_changed');
            $this->forgetChallenge($request);

            throw $exception;
        }

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->forgetChallenge($request);

        return response()->json([
            'message' => sprintf('%d migrant registrations approved.', $resolvedEntries->count()),
            'data' => $resolvedEntries->values(),
            'approvedCount' => $resolvedEntries->count(),
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

    private function isValidIntent(mixed $intent): bool
    {
        if (
            ! is_array($intent) ||
            ($intent['purpose'] ?? null) !== 'migrant-registry-bulk-approval' ||
            ($intent['decision'] ?? null) !== 'approve' ||
            ! is_numeric($intent['actorUserId'] ?? null) ||
            ! is_array($intent['targets'] ?? null) ||
            count($intent['targets']) < 1 ||
            count($intent['targets']) > 100 ||
            ! is_string($intent['challenge'] ?? null) ||
            ! is_string($intent['origin'] ?? null) ||
            ! is_string($intent['rpId'] ?? null) ||
            ! is_string($intent['expiresAt'] ?? null)
        ) {
            return false;
        }

        $entryIds = [];

        foreach ($intent['targets'] as $target) {
            if (
                ! is_array($target) ||
                ! is_numeric($target['id'] ?? null) ||
                ! is_string($target['status'] ?? null) ||
                ! is_string($target['payloadHash'] ?? null)
            ) {
                return false;
            }

            $entryIds[] = (int) $target['id'];
        }

        return count($entryIds) === count(array_unique($entryIds));
    }

    private function pendingChallengeIntent(Request $request, User $actor): SecurityChallengeIntent|JsonResponse|null
    {
        $challengeIntentId = $request->session()->get(MigrantRegistryBulkApprovalOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (! is_string($challengeIntentId) || $challengeIntentId === '') {
            return null;
        }

        $challengeIntent = $this->securityChallengeIntentService->findPendingForActor(
            $challengeIntentId,
            $actor,
            'migrant.registry.bulk_approval',
        );

        if (! $challengeIntent instanceof SecurityChallengeIntent) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Bulk migrant approval challenge is no longer pending.'], 409);
        }

        if ($challengeIntent->expires_at?->isPast()) {
            $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Bulk migrant approval challenge expired. Request a new challenge.'], 409);
        }

        return $challengeIntent;
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
            MigrantRegistryBulkApprovalOptionsController::INTENT_KEY,
            MigrantRegistryBulkApprovalOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }
}
