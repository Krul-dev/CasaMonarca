<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly BrowserDeviceService $browserDeviceService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'password',
                    'submittedEmail' => $credentials['email'],
                ],
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->isSuspended()) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'password',
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

        if ($user->hasTotpEnabled()) {
            Auth::guard('web')->logout();

            $request->session()->put(self::PENDING_TOTP_USER_KEY, $user->getKey());
            $request->session()->regenerateToken();

            $this->auditEventService->success(
                $request,
                AuditEventType::AuthTotpChallengeStarted,
                $user,
                metadata: [
                    'method' => 'password',
                ],
            );

            return response()->json([
                'message' => 'Two-factor authentication code is required.',
                'requiresTwoFactor' => true,
            ], 202);
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
                'method' => 'password',
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
    private function recordNewDeviceIfNeeded(LoginRequest $request, User $user, array $deviceContext): void
    {
        if (! $deviceContext['isNewDevice']) {
            return;
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthDeviceRegistered,
            $user,
            metadata: [
                'method' => 'password',
                ...$deviceContext['metadata'],
            ],
        );
    }
}
