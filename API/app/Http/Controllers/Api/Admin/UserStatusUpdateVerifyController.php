<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Admin\UserDirectoryViewService;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserStatusUpdateVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly UserDirectoryViewService $userDirectoryViewService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $pendingIntent = $request->session()->get(UserStatusUpdateOptionsController::INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ! is_numeric($pendingIntent['version'] ?? null) ||
            ! is_string($pendingIntent['purpose'] ?? null) ||
            ! is_numeric($pendingIntent['actorUserId'] ?? null) ||
            ! is_numeric($pendingIntent['targetUserId'] ?? null) ||
            ! is_string($pendingIntent['targetRole'] ?? null) ||
            ! is_string($pendingIntent['previousStatus'] ?? null) ||
            ! is_string($pendingIntent['action'] ?? null) ||
            ! is_string($pendingIntent['challenge'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null)
        ) {
            return response()->json([
                'message' => 'Account status authentication challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingIntent['version'] !== 1 || $pendingIntent['purpose'] !== 'admin-user-status-change') {
            return response()->json([
                'message' => 'Account status authentication challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
            $targetRole = UserRole::from((string) $pendingIntent['targetRole']);
            $previousStatus = UserStatus::from((string) $pendingIntent['previousStatus']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Account status authentication challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Account status authentication challenge expired. Request a new challenge.',
            ], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();
        $challengeIntent = null;

        if ($actor === null || (int) $actor->getKey() !== (int) $pendingIntent['actorUserId']) {
            return response()->json([
                'message' => 'Account status authentication challenge does not match the authenticated session.',
            ], 401);
        }

        $challengeIntentId = $request->session()->get(UserStatusUpdateOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (is_string($challengeIntentId) && $challengeIntentId !== '') {
            $challengeIntent = $this->securityChallengeIntentService->findPendingForActor(
                $challengeIntentId,
                $actor,
                'admin.user.status_change',
            );

            if (! $challengeIntent instanceof SecurityChallengeIntent) {
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Account status authentication challenge is no longer pending.',
                ], 401);
            }

            if ($challengeIntent->expires_at?->isPast()) {
                $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Account status authentication challenge expired. Request a new challenge.',
                ], 401);
            }

            if (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) $pendingIntent['challenge'])) ||
                (int) data_get($challengeIntent->payload, 'targetUserId') !== (int) $pendingIntent['targetUserId'] ||
                (string) data_get($challengeIntent->payload, 'action') !== (string) $pendingIntent['action']
            ) {
                $this->securityChallengeIntentService->markFailed($challengeIntent, 'intent_payload_mismatch');
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Account status authentication challenge is invalid.',
                ], 401);
            }
        }

        if ((int) $user->getKey() !== (int) $pendingIntent['targetUserId']) {
            $this->markChallengeFailed($challengeIntent, 'target_user_mismatch');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Account status authentication challenge does not match the selected account.',
            ], 401);
        }

        if (($user->role ?? UserRole::default()) !== $targetRole) {
            $this->markChallengeFailed($challengeIntent, 'target_role_changed');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Selected account role changed after the status challenge was created. Refresh the account directory and try again.',
            ], 409);
        }

        if (($user->status ?? UserStatus::default()) !== $previousStatus) {
            $this->markChallengeFailed($challengeIntent, 'target_status_changed');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Selected account status changed after the challenge was created. Refresh the account directory and try again.',
            ], 409);
        }

        $action = (string) $pendingIntent['action'];

        if ($actor->is($user)) {
            return $this->denyStatusChange(
                $request,
                $actor,
                $user,
                $action,
                'cannot_change_own_status',
                'Admins cannot suspend or reactivate their own account in this flow.',
                $challengeIntent,
            );
        }

        if ($targetRole === UserRole::Admin) {
            return $this->denyStatusChange(
                $request,
                $actor,
                $user,
                $action,
                'admin_account_status_locked',
                'Admin account status changes are deferred to the hardened admin-account flow.',
                $challengeIntent,
            );
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
                'id' => ['This security key is not registered to the current admin account.'],
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

            throw $exception;
        }

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        $reason = is_string($pendingIntent['reason'] ?? null)
            ? trim((string) $pendingIntent['reason'])
            : null;
        $newStatus = match ($action) {
            UserStatusUpdateOptionsController::REACTIVATE_ACTION => $this->reactivateUser($user),
            UserStatusUpdateOptionsController::SUSPEND_ACTION => $this->suspendUser($user, $actor, $reason),
            default => null,
        };

        if (! $newStatus instanceof UserStatus) {
            $this->markChallengeFailed($challengeIntent, 'invalid_action');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Account status action is invalid.',
            ], 401);
        }

        $updatedUser = $user->fresh()?->loadCount('webauthnCredentials') ?? $user;
        $this->forgetChallenge($request);

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->auditEventService->success(
            $request,
            $this->eventTypeFor($action),
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            $this->metadataFor($updatedUser, [
                'action' => $action,
                'challengeIntentId' => $challengeIntent?->getKey(),
                'previousStatus' => $previousStatus->value,
                'newStatus' => $newStatus->value,
                'reason' => $reason,
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'signCount' => $newSignCount,
            ]),
        );

        return response()->json([
            'message' => $newStatus === UserStatus::Suspended
                ? 'User account suspended successfully.'
                : 'User account reactivated successfully.',
            'user' => $this->userDirectoryViewService->serialize($updatedUser),
        ]);
    }

    private function suspendUser(User $user, User $actor, ?string $reason): UserStatus
    {
        $user->forceFill([
            'status' => UserStatus::Suspended,
            'suspended_at' => now(),
            'suspended_by_user_id' => $actor->getKey(),
            'suspension_reason' => $reason !== '' ? $reason : null,
        ])->save();

        return UserStatus::Suspended;
    }

    private function reactivateUser(User $user): UserStatus
    {
        $user->forceFill([
            'status' => UserStatus::Active,
            'suspended_at' => null,
            'suspended_by_user_id' => null,
            'suspension_reason' => null,
        ])->save();

        return UserStatus::Active;
    }

    private function denyStatusChange(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
        string $code,
        string $message,
        ?SecurityChallengeIntent $challengeIntent = null,
    ): JsonResponse {
        $this->markChallengeFailed($challengeIntent, $code);
        $this->forgetChallenge($request);

        $this->auditEventService->denied(
            $request,
            $this->eventTypeFor($action),
            $actor,
            [
                'type' => 'user',
                'id' => $targetUser->getKey(),
            ],
            $this->metadataFor($targetUser, [
                'action' => $action,
                'reason' => $code,
            ]),
        );

        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
            ],
        ], 403);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadataFor(User $targetUser, array $metadata = []): array
    {
        return [
            ...$metadata,
            'targetUserId' => $targetUser->getKey(),
            'targetUserName' => $targetUser->name,
            'targetUserEmail' => $targetUser->email,
        ];
    }

    private function eventTypeFor(string $action): AuditEventType
    {
        return $action === UserStatusUpdateOptionsController::REACTIVATE_ACTION
            ? AuditEventType::AdminUserEnabled
            : AuditEventType::AdminUserDisabled;
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            UserStatusUpdateOptionsController::INTENT_KEY,
            UserStatusUpdateOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
    }

    private function markChallengeFailed(?SecurityChallengeIntent $challengeIntent, string $reason): void
    {
        if ($challengeIntent instanceof SecurityChallengeIntent && $challengeIntent->isPending()) {
            $this->securityChallengeIntentService->markFailed($challengeIntent, $reason);
        }
    }
}
