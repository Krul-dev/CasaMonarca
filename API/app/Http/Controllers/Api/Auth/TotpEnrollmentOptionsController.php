<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TotpEnrollmentOptionsController extends Controller
{
    private const PENDING_TOTP_ENROLL_SECRET_KEY = 'auth.pending_totp_enrollment_secret';

    private const PENDING_TOTP_ENROLL_USER_KEY = 'auth.pending_totp_enrollment_user_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly TotpService $totpService,
    ) {}

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

        $secret = $this->totpService->generateSecret();
        $issuer = (string) config('app.name', 'CasaMonarca');
        $label = sprintf('%s:%s', $issuer, $user->email);
        $otpauthUri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($label),
            rawurlencode($secret),
            rawurlencode($issuer),
        );

        $request->session()->put(self::PENDING_TOTP_ENROLL_SECRET_KEY, $secret);
        $request->session()->put(self::PENDING_TOTP_ENROLL_USER_KEY, $user->getKey());
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthTotpEnrollmentStarted,
            $user,
            ['type' => 'session'],
            [
                'method' => 'totp-enrollment',
            ],
        );

        return response()->json([
            'message' => 'TOTP enrollment challenge created.',
            'enrollment' => [
                'secret' => $secret,
                'otpauthUri' => $otpauthUri,
                'issuer' => $issuer,
                'accountName' => $user->email,
            ],
        ]);
    }
}

