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

class UserRecoveryOptionsController extends Controller
{
    public const INTENT_KEY = 'admin.users.recovery.webauthn.intent';

    public const RESET_TOTP_ACTION = 'reset_totp';

    public const REVOKE_PASSKEYS_ACTION = 'revoke_passkeys';

    public const RESET_PASSWORD_ACTION = 'reset_password';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in([
                self::RESET_TOTP_ACTION,
                self::REVOKE_PASSKEYS_ACTION,
                self::RESET_PASSWORD_ACTION,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $action = (string) $validated['action'];
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($denyResponse = $this->denyIfRecoveryIsLocked($request, $actor, $user, $action)) {
            return $denyResponse;
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn recovery origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Account recovery requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for account recovery.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));

        $intent = [
            'version' => 1,
            'purpose' => 'admin-user-recovery',
            'actorUserId' => (int) $actor->getKey(),
            'targetUserId' => (int) $user->getKey(),
            'targetRole' => ($user->role ?? UserRole::default())->value,
            'action' => $action,
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
            AuditEventType::AdminUserRecoveryChallengeStarted,
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            $this->metadataFor($user, [
                'action' => $action,
                'reason' => $intent['reason'],
                'targetRole' => ($user->role ?? UserRole::default())->value,
                'rpId' => $originHost,
            ]),
        );

        return response()->json([
            'message' => 'Account recovery authentication challenge created.',
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
            'recovery' => [
                'action' => $action,
                'reason' => $intent['reason'],
                'targetUserId' => $user->getKey(),
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function denyIfRecoveryIsLocked(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
    ): ?JsonResponse {
        if ($actor->is($targetUser)) {
            return $this->denyRecovery(
                $request,
                $actor,
                $targetUser,
                $action,
                'cannot_recover_own_account',
                'Admins cannot recover their own account in this flow.',
            );
        }

        if ($targetUser->role === UserRole::Admin) {
            return $this->denyRecovery(
                $request,
                $actor,
                $targetUser,
                $action,
                'admin_account_recovery_locked',
                'Admin account recovery is deferred to the hardened admin-account flow.',
            );
        }

        return null;
    }

    private function denyRecovery(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
        string $code,
        string $message,
    ): JsonResponse {
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
}
