<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserStatusUpdateOptionsController extends Controller
{
    public const INTENT_KEY = 'admin.users.status.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'admin.users.status.webauthn.challenge_intent_id';

    public const SUSPEND_ACTION = 'suspend';

    public const REACTIVATE_ACTION = 'reactivate';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in([
                self::SUSPEND_ACTION,
                self::REACTIVATE_ACTION,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $action = (string) $validated['action'];
        $reason = trim((string) ($validated['reason'] ?? ''));
        $currentStatus = $user->status ?? UserStatus::default();

        if ($denyResponse = $this->denyIfStatusChangeIsLocked($request, $actor, $user, $action)) {
            return $denyResponse;
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn account-status origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Account status changes require localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for account status changes.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));

        $intent = [
            'version' => 1,
            'purpose' => 'admin-user-status-change',
            'actorUserId' => (int) $actor->getKey(),
            'targetUserId' => (int) $user->getKey(),
            'targetUserName' => $user->name,
            'targetUserEmail' => $user->email,
            'targetRole' => ($user->role ?? UserRole::default())->value,
            'previousStatus' => $currentStatus->value,
            'action' => $action,
            'reason' => $reason !== '' ? $reason : null,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'admin.user.status_change',
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
            targetType: 'user',
            targetId: $user->getKey(),
        );

        $request->session()->put([
            self::INTENT_KEY => $intent,
            self::CHALLENGE_INTENT_ID_KEY => $challengeIntent->getKey(),
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminUserStatusChangeChallengeStarted,
            $actor,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            $this->metadataFor($user, [
                'action' => $action,
                'challengeIntentId' => $challengeIntent->getKey(),
                'purpose' => 'admin.user.status_change',
                'previousStatus' => $currentStatus->value,
                'reason' => $intent['reason'],
                'rpId' => $originHost,
                'challengeStarted' => true,
            ]),
        );

        return response()->json([
            'message' => 'Account status authentication challenge created.',
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
            'statusChange' => [
                'action' => $action,
                'targetUserId' => $user->getKey(),
                'previousStatus' => $currentStatus->value,
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

    private function denyIfStatusChangeIsLocked(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
    ): ?JsonResponse {
        if ($actor->is($targetUser)) {
            return $this->denyStatusChange(
                $request,
                $actor,
                $targetUser,
                $action,
                'cannot_change_own_status',
                'Admins cannot suspend or reactivate their own account in this flow.',
            );
        }

        if ($targetUser->role === UserRole::Admin) {
            return $this->denyStatusChange(
                $request,
                $actor,
                $targetUser,
                $action,
                'admin_account_status_locked',
                'Admin account status changes are deferred to the hardened admin-account flow.',
            );
        }

        return null;
    }

    private function denyStatusChange(
        Request $request,
        User $actor,
        User $targetUser,
        string $action,
        string $code,
        string $message,
    ): JsonResponse {
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
        return $action === self::REACTIVATE_ACTION
            ? AuditEventType::AdminUserEnabled
            : AuditEventType::AdminUserDisabled;
    }
}
