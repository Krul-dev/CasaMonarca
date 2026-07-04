<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\TotpLoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Auth\TotpService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TotpLoginController extends Controller
{
    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly BrowserDeviceService $browserDeviceService,
        private readonly TotpService $totpService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(TotpLoginRequest $request): JsonResponse
    {
        $pendingUserId = $request->session()->get(self::PENDING_TOTP_USER_KEY);

        if (! is_numeric($pendingUserId)) {
            return response()->json([
                'message' => 'Two-factor challenge was not initiated.',
            ], 401);
        }

        /** @var User|null $user */
        $user = User::query()->find($pendingUserId);

        if ($user === null || ! $user->hasTotpEnabled()) {
            $request->session()->forget(self::PENDING_TOTP_USER_KEY);

            return response()->json([
                'message' => 'Two-factor challenge is no longer available.',
            ], 401);
        }

        if ($user->isSuspended()) {
            $request->session()->forget(self::PENDING_TOTP_USER_KEY);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'totp',
                    'reason' => 'account_suspended',
                ],
            );

            return response()->json([
                'message' => 'This account is suspended.',
                'error' => [
                    'code' => 'account_suspended',
                ],
            ], 403);
        }

        if (! $this->totpService->verify((string) $user->two_factor_secret, (string) $request->string('code'))) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthTotpChallengeFailed,
                $user,
                metadata: [
                    'method' => 'totp',
                ],
            );

            throw ValidationException::withMessages([
                'code' => ['The authentication code is invalid or expired.'],
            ]);
        }

        $request->session()->forget(self::PENDING_TOTP_USER_KEY);
        Auth::guard('web')->login($user);
        $user->forceFill([
            'last_sign_in_at' => now(),
        ])->save();

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $deviceContext = $this->browserDeviceService->rememberAuthenticatedDevice($request, $user);

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthLoginSucceeded,
            $user,
            metadata: [
                'method' => 'totp',
                ...$deviceContext['metadata'],
            ],
        );

        $this->recordNewDeviceIfNeeded($request, $user, $deviceContext);

        return response()->json([
            'message' => 'Login successful.',
            'requiresTwoFactor' => false,
            'user' => $this->authenticatedUserViewService->toArray($user->fresh() ?? $user),
        ]);
    }

    /**
     * @param  array{isNewDevice: bool, metadata: array<string, mixed>}  $deviceContext
     */
    private function recordNewDeviceIfNeeded(TotpLoginRequest $request, User $user, array $deviceContext): void
    {
        if (! $deviceContext['isNewDevice']) {
            return;
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthDeviceRegistered,
            $user,
            metadata: [
                'method' => 'totp',
                ...$deviceContext['metadata'],
            ],
        );
    }
}
