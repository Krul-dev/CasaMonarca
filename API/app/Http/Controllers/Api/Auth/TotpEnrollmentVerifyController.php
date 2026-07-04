<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\TotpService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TotpEnrollmentVerifyController extends Controller
{
    private const PENDING_TOTP_ENROLL_SECRET_KEY = 'auth.pending_totp_enrollment_secret';

    private const PENDING_TOTP_ENROLL_USER_KEY = 'auth.pending_totp_enrollment_user_id';

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly TotpService $totpService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->hasTotpEnabled()) {
            return response()->json([
                'message' => 'TOTP is already enabled for this account.',
                'error' => [
                    'code' => 'totp_already_enabled',
                ],
            ], 409);
        }

        $pendingUserId = $request->session()->get(self::PENDING_TOTP_ENROLL_USER_KEY);
        $pendingSecret = $request->session()->get(self::PENDING_TOTP_ENROLL_SECRET_KEY);

        if (! is_numeric($pendingUserId) || ! is_string($pendingSecret) || $pendingSecret === '') {
            return response()->json([
                'message' => 'TOTP enrollment challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingUserId !== (int) $user->getKey()) {
            return response()->json([
                'message' => 'TOTP enrollment challenge does not match the current user.',
            ], 401);
        }

        $payload = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        if (! $this->totpService->verify($pendingSecret, (string) $payload['code'])) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthTotpEnrollmentFailed,
                $user,
                ['type' => 'session'],
                [
                    'method' => 'totp-enrollment',
                    'reason' => 'invalid_code',
                ],
            );

            throw ValidationException::withMessages([
                'code' => ['The authentication code is invalid or expired.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $pendingSecret,
        ])->save();

        $request->session()->forget([
            self::PENDING_TOTP_ENROLL_SECRET_KEY,
            self::PENDING_TOTP_ENROLL_USER_KEY,
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthTotpEnrolled,
            $user,
            ['type' => 'session'],
            [
                'method' => 'totp-enrollment',
            ],
        );

        return response()->json([
            'message' => 'TOTP enrolled successfully.',
            'user' => $this->authenticatedUserViewService->toArray($user->fresh()),
        ]);
    }
}

