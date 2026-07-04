<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserRoleUpdateOptionsController extends Controller
{
    public const INTENT_KEY = 'admin.users.role_change.webauthn.intent';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([
                UserRole::Coordinator->value,
                UserRole::NonCoordinator->value,
                UserRole::Volunteer->value,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $targetRole = UserRole::from((string) $validated['role']);
        $previousRole = $user->role ?? UserRole::default();
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($denyResponse = $this->denyIfRoleChangeIsLocked($request, $actor, $user, $previousRole, $targetRole)) {
            return $denyResponse;
        }

        if ($denyResponse = $this->denyIfCoordinatorPromotionIsIncomplete($request, $actor, $user, $previousRole, $targetRole)) {
            return $denyResponse;
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn role-assignment origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Role assignment requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for role assignment.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));

        $intent = [
            'version' => 1,
            'purpose' => 'admin-user-role-change',
            'actorUserId' => (int) $actor->getKey(),
            'targetUserId' => (int) $user->getKey(),
            'previousRole' => $previousRole->value,
            'targetRole' => $targetRole->value,
            'reason' => $reason !== '' ? $reason : null,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];

        $request->session()->put(self::INTENT_KEY, $intent);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminUserRoleChangeChallengeStarted,
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            [
                'previousRole' => $previousRole->value,
                'targetRole' => $targetRole->value,
                'reason' => $intent['reason'],
                'targetUserId' => $user->getKey(),
                'targetUserName' => $user->name,
                'targetUserEmail' => $user->email,
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'Role assignment authentication challenge created.',
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
            'assignment' => [
                'targetUserId' => $user->getKey(),
                'previousRole' => $previousRole->value,
                'targetRole' => $targetRole->value,
                'reason' => $intent['reason'],
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function denyIfRoleChangeIsLocked(
        Request $request,
        User $actor,
        User $targetUser,
        UserRole $previousRole,
        UserRole $attemptedRole,
    ): ?JsonResponse {
        if ($actor->is($targetUser)) {
            return $this->denyRoleChange(
                $request,
                $actor,
                $targetUser,
                $previousRole,
                $attemptedRole,
                'cannot_change_own_role',
                'Admins cannot change their own role in this flow.',
            );
        }

        if ($previousRole === UserRole::Admin) {
            return $this->denyRoleChange(
                $request,
                $actor,
                $targetUser,
                $previousRole,
                $attemptedRole,
                'admin_account_role_locked',
                'Admin account role changes are deferred to the hardened admin-account flow.',
            );
        }

        return null;
    }

    private function denyIfCoordinatorPromotionIsIncomplete(
        Request $request,
        User $actor,
        User $targetUser,
        UserRole $previousRole,
        UserRole $attemptedRole,
    ): ?JsonResponse {
        if (
            $attemptedRole !== UserRole::Coordinator ||
            $previousRole === UserRole::Coordinator ||
            $targetUser->webauthnCredentials()->exists()
        ) {
            return null;
        }

        return $this->denyRoleChange(
            $request,
            $actor,
            $targetUser,
            $previousRole,
            $attemptedRole,
            'coordinator_passkey_required',
            'Cannot promote this account to coordinator until the user registers a passkey.',
            422,
        );
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
}
