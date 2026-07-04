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
use Illuminate\Validation\ValidationException;

class UserRoleUpdateVerifyController extends Controller
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
        $pendingIntent = $request->session()->get(UserRoleUpdateOptionsController::INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ! is_numeric($pendingIntent['version'] ?? null) ||
            ! is_string($pendingIntent['purpose'] ?? null) ||
            ! is_numeric($pendingIntent['actorUserId'] ?? null) ||
            ! is_numeric($pendingIntent['targetUserId'] ?? null) ||
            ! is_string($pendingIntent['previousRole'] ?? null) ||
            ! is_string($pendingIntent['targetRole'] ?? null) ||
            ! is_string($pendingIntent['challenge'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null)
        ) {
            return response()->json([
                'message' => 'Role assignment authentication challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingIntent['version'] !== 1 || $pendingIntent['purpose'] !== 'admin-user-role-change') {
            return response()->json([
                'message' => 'Role assignment authentication challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
            $previousRole = UserRole::from((string) $pendingIntent['previousRole']);
            $targetRole = UserRole::from((string) $pendingIntent['targetRole']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Role assignment authentication challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Role assignment authentication challenge expired. Request a new challenge.',
            ], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if ($actor === null || (int) $actor->getKey() !== (int) $pendingIntent['actorUserId']) {
            return response()->json([
                'message' => 'Role assignment authentication challenge does not match the authenticated session.',
            ], 401);
        }

        if ((int) $user->getKey() !== (int) $pendingIntent['targetUserId']) {
            return response()->json([
                'message' => 'Role assignment authentication challenge does not match the selected account.',
            ], 401);
        }

        $currentRole = $user->role ?? UserRole::default();

        if ($currentRole !== $previousRole) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Selected account role changed after the challenge was created. Refresh the account directory and try again.',
            ], 409);
        }

        if ($actor->is($user)) {
            return $this->denyRoleChange(
                $request,
                $actor,
                $user,
                $previousRole,
                $targetRole,
                'cannot_change_own_role',
                'Admins cannot change their own role in this flow.',
            );
        }

        if ($previousRole === UserRole::Admin) {
            return $this->denyRoleChange(
                $request,
                $actor,
                $user,
                $previousRole,
                $targetRole,
                'admin_account_role_locked',
                'Admin account role changes are deferred to the hardened admin-account flow.',
            );
        }

        if (
            $targetRole === UserRole::Coordinator &&
            $previousRole !== UserRole::Coordinator &&
            ! $user->webauthnCredentials()->exists()
        ) {
            return $this->denyRoleChange(
                $request,
                $actor,
                $user,
                $previousRole,
                $targetRole,
                'coordinator_passkey_required',
                'Cannot promote this account to coordinator until the user registers a passkey.',
                422,
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

        if ($previousRole !== $targetRole) {
            $user->forceFill([
                'role' => $targetRole,
            ])->save();
        }

        $updatedUser = $user->fresh()?->loadCount('webauthnCredentials') ?? $user;
        $this->forgetChallenge($request);

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminUserRoleChanged,
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            [
                'previousRole' => $previousRole->value,
                'newRole' => $targetRole->value,
                'reason' => is_string($pendingIntent['reason'] ?? null)
                    ? trim((string) $pendingIntent['reason'])
                    : null,
                'targetUserId' => $user->getKey(),
                'targetUserName' => $user->name,
                'targetUserEmail' => $user->email,
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'signCount' => $newSignCount,
            ],
        );

        return response()->json([
            'message' => $previousRole === $targetRole
                ? 'User already has the requested role.'
                : 'User role updated successfully.',
            'user' => $this->userDirectoryViewService->serialize($updatedUser),
        ]);
    }

    private function denyRoleChange(
        Request $request,
        User $actor,
        User $targetUser,
        UserRole $previousRole,
        UserRole $attemptedRole,
        string $code,
        string $message,
        int $status = 403,
    ): JsonResponse {
        $this->forgetChallenge($request);

        $this->auditEventService->denied(
            $request,
            AuditEventType::AdminUserRoleChanged,
            $actor,
            [
                'type' => 'user',
                'id' => $targetUser->getKey(),
            ],
            [
                'previousRole' => $previousRole->value,
                'attemptedRole' => $attemptedRole->value,
                'reason' => $code,
                'targetUserId' => $targetUser->getKey(),
                'targetUserName' => $targetUser->name,
                'targetUserEmail' => $targetUser->email,
            ],
        );

        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
            ],
        ], $status);
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(UserRoleUpdateOptionsController::INTENT_KEY);
    }
}
