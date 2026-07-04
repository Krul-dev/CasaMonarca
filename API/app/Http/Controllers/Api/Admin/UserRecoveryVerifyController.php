<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Admin\UserDirectoryViewService;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserRecoveryVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly UserDirectoryViewService $userDirectoryViewService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $pendingIntent = $request->session()->get(UserRecoveryOptionsController::INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ! is_numeric($pendingIntent['version'] ?? null) ||
            ! is_string($pendingIntent['purpose'] ?? null) ||
            ! is_numeric($pendingIntent['actorUserId'] ?? null) ||
            ! is_numeric($pendingIntent['targetUserId'] ?? null) ||
            ! is_string($pendingIntent['targetRole'] ?? null) ||
            ! is_string($pendingIntent['action'] ?? null) ||
            ! is_string($pendingIntent['challenge'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null)
        ) {
            return response()->json([
                'message' => 'Account recovery authentication challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingIntent['version'] !== 1 || $pendingIntent['purpose'] !== 'admin-user-recovery') {
            return response()->json([
                'message' => 'Account recovery authentication challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
            $targetRole = UserRole::from((string) $pendingIntent['targetRole']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Account recovery authentication challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Account recovery authentication challenge expired. Request a new challenge.',
            ], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if ($actor === null || (int) $actor->getKey() !== (int) $pendingIntent['actorUserId']) {
            return response()->json([
                'message' => 'Account recovery authentication challenge does not match the authenticated session.',
            ], 401);
        }

        if ((int) $user->getKey() !== (int) $pendingIntent['targetUserId']) {
            return response()->json([
                'message' => 'Account recovery authentication challenge does not match the selected account.',
            ], 401);
        }

        if (($user->role ?? UserRole::default()) !== $targetRole) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Selected account role changed after the recovery challenge was created. Refresh the account directory and try again.',
            ], 409);
        }

        if ($actor->is($user)) {
            return $this->denyRecovery(
                $request,
                $actor,
                $user,
                (string) $pendingIntent['action'],
                'cannot_recover_own_account',
                'Admins cannot recover their own account in this flow.',
            );
        }

        if ($targetRole === UserRole::Admin) {
            return $this->denyRecovery(
                $request,
                $actor,
                $user,
                (string) $pendingIntent['action'],
                'admin_account_recovery_locked',
                'Admin account recovery is deferred to the hardened admin-account flow.',
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

        $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
            $payload,
            $credential,
            (string) $pendingIntent['challenge'],
            (string) $pendingIntent['origin'],
            (string) $pendingIntent['rpId'],
        );

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        $action = (string) $pendingIntent['action'];
        $result = match ($action) {
            UserRecoveryOptionsController::RESET_TOTP_ACTION => [
                'eventType' => $this->resetTotp($user),
                'message' => 'TOTP enrollment reset successfully.',
                'passwordReset' => null,
            ],
            UserRecoveryOptionsController::REVOKE_PASSKEYS_ACTION => [
                'eventType' => $this->revokePasskeys($user),
                'message' => 'Passkeys revoked successfully.',
                'passwordReset' => null,
            ],
            UserRecoveryOptionsController::RESET_PASSWORD_ACTION => [
                'eventType' => AuditEventType::AdminUserPasswordResetIssued,
                'message' => 'Password reset link issued successfully.',
                'passwordReset' => $this->issuePasswordReset($user),
            ],
            default => null,
        };

        if (! is_array($result) || ! ($result['eventType'] ?? null) instanceof AuditEventType) {
            return response()->json([
                'message' => 'Account recovery action is invalid.',
            ], 401);
        }

        $updatedUser = $user->fresh()?->loadCount('webauthnCredentials') ?? $user;
        $this->forgetChallenge($request);

        $this->auditEventService->success(
            $request,
            $result['eventType'],
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            $this->metadataFor($updatedUser, [
                'action' => $action,
                'reason' => is_string($pendingIntent['reason'] ?? null)
                    ? trim((string) $pendingIntent['reason'])
                    : null,
                'targetRole' => $targetRole->value,
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'signCount' => $newSignCount,
                ...($result['passwordReset'] !== null ? [
                    'resetExpiresAt' => $result['passwordReset']['expiresAt'],
                ] : []),
            ]),
        );

        $response = [
            'message' => $result['message'],
            'user' => $this->userDirectoryViewService->serialize($updatedUser),
        ];

        if ($result['passwordReset'] !== null) {
            $response['passwordReset'] = $result['passwordReset'];
        }

        return response()->json($response);
    }

    private function resetTotp(User $user): AuditEventType
    {
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ])->save();

        return AuditEventType::AdminUserTotpReset;
    }

    private function revokePasskeys(User $user): AuditEventType
    {
        $user->webauthnCredentials()->delete();

        return AuditEventType::AdminUserPasskeysRevoked;
    }

    /**
     * @return array{email: string, expiresAt: string, resetPath: string, token: string}
     */
    private function issuePasswordReset(User $user): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = CarbonImmutable::now('UTC')->addHour();

        DB::transaction(function () use ($user, $token): void {
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now('UTC'),
                ],
            );

            $user->forceFill([
                'password' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='),
                'remember_token' => null,
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->delete();
        });

        return [
            'email' => $user->email,
            'token' => $token,
            'resetPath' => '/reset-password?email='.rawurlencode($user->email).'&token='.rawurlencode($token),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    private function denyRecovery(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
        string $code,
        string $message,
    ): JsonResponse {
        $this->forgetChallenge($request);

        $this->auditEventService->denied(
            $request,
            AuditEventType::AdminUserRecoveryChallengeStarted,
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

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(UserRecoveryOptionsController::INTENT_KEY);
    }
}
