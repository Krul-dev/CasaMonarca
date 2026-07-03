<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AccountInvite;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AccountInviteRedeemController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $token = (string) $validated['token'];
        $normalizedEmail = mb_strtolower(trim((string) $validated['email']));
        $tokenHash = hash('sha256', $token);
        $rateLimitKey = sprintf('invites:redeem:%s:%s', (string) $request->ip(), substr($tokenHash, 0, 20));

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AccountInviteRedemptionFailed,
                null,
                ['type' => 'account_invite'],
                [
                    'reason' => 'rate_limited',
                    'retryAfterSeconds' => RateLimiter::availableIn($rateLimitKey),
                ],
            );

            return response()->json([
                'message' => 'Too many invite redemption attempts. Please retry later.',
                'error' => [
                    'code' => 'invite_redeem_rate_limited',
                    'retryAfterSeconds' => RateLimiter::availableIn($rateLimitKey),
                ],
            ], 429);
        }

        $invite = AccountInvite::query()
            ->where('token_hash', $tokenHash)
            ->whereNotNull('issued_at')
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now('UTC'))
            ->first();

        if (! $invite instanceof AccountInvite) {
            $this->recordFailedAttempt($rateLimitKey);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AccountInviteRedemptionFailed,
                null,
                ['type' => 'account_invite'],
                [
                    'reason' => 'invalid_or_expired_token',
                    'tokenHashPrefix' => substr($tokenHash, 0, 12),
                ],
            );

            return response()->json([
                'message' => 'Invite token is invalid or expired.',
                'error' => [
                    'code' => 'invalid_invite_token',
                ],
            ], 422);
        }

        if ($normalizedEmail !== mb_strtolower($invite->email)) {
            $this->recordFailedAttempt($rateLimitKey);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AccountInviteRedemptionFailed,
                null,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'reason' => 'invite_email_mismatch',
                    'targetEmailHash' => hash('sha256', $invite->email),
                ],
            );

            return response()->json([
                'message' => 'Invite email does not match this registration attempt.',
                'error' => [
                    'code' => 'invite_email_mismatch',
                ],
            ], 422);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists()) {
            $this->recordFailedAttempt($rateLimitKey);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AccountInviteRedemptionFailed,
                null,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'reason' => 'email_already_registered',
                    'targetEmailHash' => hash('sha256', $normalizedEmail),
                ],
            );

            return response()->json([
                'message' => 'Email is already registered.',
                'error' => [
                    'code' => 'invite_email_already_registered',
                ],
            ], 422);
        }

        /** @var User $user */
        $user = DB::transaction(function () use ($validated, $normalizedEmail, $invite): User {
            $role = $invite->role instanceof UserRole ? $invite->role : UserRole::NonCoordinator;

            $user = User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => $normalizedEmail,
                'password' => (string) $validated['password'],
                'role' => $role->value,
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
            ]);

            $invite->forceFill([
                'used_at' => now('UTC'),
            ])->save();

            return $user;
        });

        $this->auditEventService->success(
            $request,
            AuditEventType::AccountInviteRedeemed,
            $user,
            ['type' => 'account_invite', 'id' => $invite->getKey()],
            [
                'role' => $user->role?->value,
            ],
        );

        RateLimiter::clear($rateLimitKey);

        $requirements = $this->enrollmentRequirements($user->role ?? UserRole::NonCoordinator);

        return response()->json([
            'message' => 'Invite redeemed successfully.',
            'user' => [
                'id' => $user->getKey(),
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role?->value,
            ],
            'enrollment' => [
                'requiresTotp' => $requirements['requiresTotp'],
                'requiresPasskey' => $requirements['requiresPasskey'],
            ],
        ], 201);
    }

    /**
     * @return array{requiresTotp: bool, requiresPasskey: bool}
     */
    private function enrollmentRequirements(UserRole $role): array
    {
        return [
            'requiresTotp' => in_array($role, [UserRole::Admin, UserRole::Coordinator, UserRole::NonCoordinator, UserRole::Volunteer], true),
            'requiresPasskey' => in_array($role, [UserRole::Admin, UserRole::Coordinator], true),
        ];
    }

    private function recordFailedAttempt(string $rateLimitKey): void
    {
        RateLimiter::hit($rateLimitKey, 900);
    }
}
