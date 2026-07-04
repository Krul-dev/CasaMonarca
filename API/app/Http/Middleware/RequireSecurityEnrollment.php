<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\SecurityEnrollmentService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecurityEnrollment
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly SecurityEnrollmentService $securityEnrollmentService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $security = $this->securityEnrollmentService->summaryForUser($user);

        if (! $security['enforced'] || $security['isFullyEnrolled']) {
            return $next($request);
        }

        $this->auditEventService->denied(
            $request,
            AuditEventType::AuthAuthorizationDenied,
            $user,
            metadata: [
                'action' => 'require-security-enrollment',
                'path' => $request->path(),
                'requires' => $security['requires'],
                'enrolled' => $security['enrolled'],
                'missing' => $security['missing'],
                'currentRole' => $user->role?->value,
            ],
        );

        return $this->forbiddenResponse($security);
    }

    /**
     * @param  array{
     *     enrolled: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     *     missing: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     *     requires: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     * }  $security
     */
    private function forbiddenResponse(array $security): JsonResponse
    {
        return response()->json([
            'message' => 'Security enrollment is required before accessing this module.',
            'error' => [
                'code' => 'security_enrollment_required',
                'requires' => $security['requires'],
                'enrolled' => $security['enrolled'],
                'missing' => $security['missing'],
                'enrollmentEndpoints' => [
                    'totpOptions' => '/totp/enroll/options',
                    'totpVerify' => '/totp/enroll/verify',
                    'passkeyOptions' => '/webauthn/register/options',
                    'passkeyVerify' => '/webauthn/register/verify',
                ],
            ],
        ], 403);
    }
}
